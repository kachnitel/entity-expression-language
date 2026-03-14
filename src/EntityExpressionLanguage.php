<?php

declare(strict_types=1);

namespace Kachnitel\EntityExpressionLanguage;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Evaluates Symfony ExpressionLanguage strings against any PHP object.
 *
 * Entity properties are accessed via Symfony's PropertyAccess component, so
 * `entity.status` calls `getStatus()`, `isStatus()`, or reads a public property —
 * exactly like Twig. Private properties are resolved through their getters.
 *
 * An optional `is_granted()` function is registered for expressions that need to
 * incorporate security checks alongside property-based conditions.
 *
 * ## Supported syntax
 *
 * ```
 * entity.status == "pending"
 * entity.stock > 0
 * entity.active && is_granted("ROLE_EDITOR")
 * entity.status == "draft" || entity.status == "review"
 * is_granted("ROLE_ADMIN", entity)
 * item.status == "active"         // "item" is an alias for "entity"
 * entity.getStatus() == "pending" // explicit method call also works
 * ```
 *
 * ## Error handling
 *
 * Returns `false` on any parse or runtime error — a misconfigured expression
 * silently evaluates to false rather than throwing.
 */
class EntityExpressionLanguage
{
    private ExpressionLanguage $expressionLanguage;
    private PropertyAccessorInterface $propertyAccessor;

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();

        // Register is_granted(attribute, subject = null)
        $this->expressionLanguage->register(
            'is_granted',
            // Compiler (for compiled expressions — not used at runtime, but required by the API)
            static fn (string $attribute, string $subject = 'null'): string => sprintf(
                '($auth !== null && $auth->isGranted(%s, %s))',
                $attribute,
                $subject,
            ),
            // Evaluator
            static function (array $arguments, string $attribute, mixed $subject = null): bool {
                /** @var AuthorizationCheckerInterface|null $auth */
                $auth = $arguments['auth'] ?? null;

                if ($auth === null) {
                    return false;
                }

                // Unwrap proxy so voters receive the real entity, not the proxy wrapper
                if ($subject instanceof PropertyAccessProxy) {
                    $subject = $subject->getEntity();
                }

                return $auth->isGranted($attribute, $subject);
            },
        );
    }

    /**
     * Evaluate an expression against an entity row.
     *
     * The entity is wrapped in a {@see PropertyAccessProxy} so that `entity.status`
     * automatically resolves to `getStatus()`, `isStatus()`, or a public property.
     *
     * Returns `false` on any error (parse failure, missing property, etc.).
     *
     * @param object                             $entity      The object being evaluated
     * @param AuthorizationCheckerInterface|null $authChecker Required only when the expression uses is_granted()
     */
    public function evaluate(
        string $expression,
        object $entity,
        ?AuthorizationCheckerInterface $authChecker = null,
    ): bool {
        $proxy = new PropertyAccessProxy($entity, $this->propertyAccessor);

        try {
            return (bool) $this->expressionLanguage->evaluate(
                $expression,
                [
                    'entity' => $proxy,
                    'item'   => $proxy, // alias for convenience
                    'auth'   => $authChecker,
                ],
            );
        } catch (\Exception) {
            return false;
        }
    }
}

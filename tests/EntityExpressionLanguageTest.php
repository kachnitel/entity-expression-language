<?php

declare(strict_types=1);

namespace Kachnitel\EntityExpressionLanguage\Tests;

use Kachnitel\EntityExpressionLanguage\EntityExpressionLanguage;
use Kachnitel\EntityExpressionLanguage\PropertyAccessProxy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @covers \Kachnitel\EntityExpressionLanguage\EntityExpressionLanguage
 * @covers \Kachnitel\EntityExpressionLanguage\PropertyAccessProxy
 */
class EntityExpressionLanguageTest extends TestCase
{
    /** @var AuthorizationCheckerInterface&MockObject */
    private AuthorizationCheckerInterface $authChecker;

    protected function setUp(): void
    {
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Entity with PUBLIC properties — baseline coverage.
     */
    private function entity(mixed $status = 'pending', bool $active = true, int $stock = 10): object
    {
        return new class ($status, $active, $stock) {
            public function __construct(
                public readonly mixed $status,
                public readonly bool $active,
                public readonly int $stock,
            ) {}

            public function getStatus(): mixed { return $this->status; }
            public function isActive(): bool { return $this->active; }
            public function getStock(): int { return $this->stock; }
        };
    }

    /**
     * Entity with PRIVATE properties — verifies PropertyAccess proxy resolves getters.
     * This is the realistic Doctrine entity shape.
     */
    private function privateEntity(string $status = 'pending', bool $active = true): object
    {
        return new class ($status, $active) {
            private string $status;
            private bool $active;

            public function __construct(string $status, bool $active)
            {
                $this->status = $status;
                $this->active = $active;
            }

            public function getStatus(): string { return $this->status; }
            public function isActive(): bool { return $this->active; }
        };
    }

    // ── Simple property expressions ───────────────────────────────────────────

    /** @test */
    public function equalityCheckReturnsTrueWhenMatch(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'pending');

        $this->assertTrue($lang->evaluate('entity.status == "pending"', $entity));
    }

    /** @test */
    public function equalityCheckReturnsFalseWhenNoMatch(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'archived');

        $this->assertFalse($lang->evaluate('entity.status == "pending"', $entity));
    }

    /** @test */
    public function inequalityCheck(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'archived');

        $this->assertFalse($lang->evaluate('entity.status != "archived"', $entity));
    }

    /** @test */
    public function booleanPropertyCheck(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('entity.active', $this->entity(active: true)));
        $this->assertFalse($lang->evaluate('entity.active', $this->entity(active: false)));
    }

    /** @test */
    public function negationWithNotOperator(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('not entity.active', $this->entity(active: false)));
        $this->assertFalse($lang->evaluate('not entity.active', $this->entity(active: true)));
    }

    /** @test */
    public function exclamationNegation(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('!entity.active', $this->entity(active: false)));
        $this->assertFalse($lang->evaluate('!entity.active', $this->entity(active: true)));
    }

    /** @test */
    public function numericGreaterThanCheck(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('entity.stock > 0', $this->entity(stock: 5)));
        $this->assertFalse($lang->evaluate('entity.stock > 0', $this->entity(stock: 0)));
    }

    /** @test */
    public function itemPrefixWorksAsAliasForEntity(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'pending');

        $this->assertTrue($lang->evaluate('item.status == "pending"', $entity));
    }

    // ── Combining conditions ──────────────────────────────────────────────────

    /** @test */
    public function andOperatorRequiresBothTrue(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'pending', active: true);

        $this->assertTrue($lang->evaluate('entity.status == "pending" && entity.active', $entity));
        $this->assertFalse($lang->evaluate('entity.status == "pending" && !entity.active', $entity));
    }

    /** @test */
    public function orOperatorRequiresAtLeastOneTrue(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'archived', active: true);

        $this->assertTrue($lang->evaluate('entity.status == "pending" || entity.active', $entity));
        $this->assertFalse($lang->evaluate('entity.status == "pending" || !entity.active', $entity));
    }

    /** @test */
    public function complexCombinedExpression(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'pending', stock: 5);

        $this->assertTrue(
            $lang->evaluate('entity.status == "pending" && entity.stock > 0', $entity)
        );
    }

    // ── is_granted() function ─────────────────────────────────────────────────

    /** @test */
    public function isGrantedReturnsTrueWhenRoleGranted(): void
    {
        $this->authChecker->method('isGranted')->with('ROLE_ADMIN', null)->willReturn(true);
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity();

        $this->assertTrue($lang->evaluate('is_granted("ROLE_ADMIN")', $entity, $this->authChecker));
    }

    /** @test */
    public function isGrantedReturnsFalseWhenRoleNotGranted(): void
    {
        $this->authChecker->method('isGranted')->with('ROLE_SUPER_ADMIN', null)->willReturn(false);
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity();

        $this->assertFalse($lang->evaluate('is_granted("ROLE_SUPER_ADMIN")', $entity, $this->authChecker));
    }

    /** @test */
    public function isGrantedReturnsFalseWhenAuthCheckerNotProvided(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity();

        $this->assertFalse($lang->evaluate('is_granted("ROLE_ADMIN")', $entity));
    }

    /** @test */
    public function isGrantedWithSingleQuotes(): void
    {
        $this->authChecker->method('isGranted')->with('ROLE_ADMIN', null)->willReturn(true);
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate("is_granted('ROLE_ADMIN')", $this->entity(), $this->authChecker));
    }

    /** @test */
    public function isGrantedCombinedWithPropertyCondition(): void
    {
        $this->authChecker->method('isGranted')->with('ROLE_EDITOR', null)->willReturn(true);
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity(status: 'pending');

        $this->assertTrue(
            $lang->evaluate('entity.status == "pending" && is_granted("ROLE_EDITOR")', $entity, $this->authChecker)
        );
    }

    /** @test */
    public function isGrantedWithEntitySubjectUnwrapsProxy(): void
    {
        $realEntity = $this->entity(status: 'pending');

        $this->authChecker
            ->method('isGranted')
            ->with(
                'ADMIN_EDIT',
                $this->callback(fn (mixed $subject) => !($subject instanceof PropertyAccessProxy)),
            )
            ->willReturn(true);

        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('is_granted("ADMIN_EDIT", entity)', $realEntity, $this->authChecker));
    }

    // ── Error / safe-default behaviour ────────────────────────────────────────

    /** @test */
    public function invalidExpressionReturnsFalse(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->entity();

        $this->assertFalse($lang->evaluate('entity.nonExistentProperty == true', $entity));
    }

    /** @test */
    public function malformedExpressionReturnsFalse(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertFalse($lang->evaluate('&&& invalid expression', $this->entity()));
    }

    /** @test */
    public function nullComparisonReturnsTrueWhenNull(): void
    {
        $lang = new EntityExpressionLanguage();

        $entity = new class () {
            public ?string $verifiedAt = null;
            public function getVerifiedAt(): ?string { return $this->verifiedAt; }
        };

        $this->assertTrue($lang->evaluate('entity.verifiedAt == null', $entity));
    }

    /** @test */
    public function nullComparisonReturnsFalseWhenNotNull(): void
    {
        $lang = new EntityExpressionLanguage();

        $entity = new class () {
            public ?string $verifiedAt = '2024-01-01';
            public function getVerifiedAt(): ?string { return $this->verifiedAt; }
        };

        $this->assertFalse($lang->evaluate('entity.verifiedAt == null', $entity));
    }

    // ── PropertyAccess proxy — private properties via getters ─────────────────

    /** @test */
    public function propertyAccessProxyCallsGetterForPrivateProperty(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->privateEntity(status: 'pending');

        $this->assertTrue($lang->evaluate('entity.status == "pending"', $entity));
    }

    /** @test */
    public function propertyAccessProxyCallsIsGetterForPrivateBooleanProperty(): void
    {
        $lang = new EntityExpressionLanguage();

        $this->assertTrue($lang->evaluate('entity.active', $this->privateEntity(active: true)));
        $this->assertFalse($lang->evaluate('entity.active', $this->privateEntity(active: false)));
    }

    /** @test */
    public function explicitMethodCallSyntaxStillWorks(): void
    {
        $lang = new EntityExpressionLanguage();
        $entity = $this->privateEntity(status: 'pending');

        $this->assertTrue($lang->evaluate('entity.getStatus() == "pending"', $entity));
    }
}

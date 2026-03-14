# kachnitel/entity-expression-language

Evaluates [Symfony ExpressionLanguage](https://symfony.com/doc/current/components/expression_language.html) strings against PHP objects, resolving properties through Symfony's `PropertyAccess` component with an optional `is_granted()` security function.

## Installation

```bash
composer require kachnitel/entity-expression-language
```

## Usage

```php
use Kachnitel\EntityExpressionLanguage\EntityExpressionLanguage;

$lang = new EntityExpressionLanguage();

// Simple property comparison — calls getStatus() automatically
$lang->evaluate('entity.status == "pending"', $order);                  // bool

// Boolean property — calls isActive()
$lang->evaluate('entity.active', $product);

// Numeric comparison
$lang->evaluate('entity.stock > 0', $product);

// Combined conditions
$lang->evaluate('entity.status == "pending" && entity.stock > 0', $product);

// "item" is an alias for "entity"
$lang->evaluate('item.status == "draft"', $article);

// Explicit method call syntax
$lang->evaluate('entity.getStatus() == "pending"', $order);
```

### Security checks with `is_granted()`

Pass an `AuthorizationCheckerInterface` as the third argument to enable `is_granted()`:

```php
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

$lang->evaluate(
    'entity.status != "locked" && is_granted("ROLE_EDITOR")',
    $entity,
    $authorizationChecker,
);

// Pass entity as subject to a voter
$lang->evaluate('is_granted("ADMIN_EDIT", entity)', $entity, $authorizationChecker);
```

`is_granted()` returns `false` when no `AuthorizationCheckerInterface` is provided.

### Error handling

Any parse or runtime error returns `false` — a misconfigured expression never throws.

## Requirements

- PHP 8.1+
- `symfony/expression-language` ^6.4|^7.0|^8.0
- `symfony/property-access` ^6.4|^7.0|^8.0
- `symfony/security-core` ^6.4|^7.0|^8.0

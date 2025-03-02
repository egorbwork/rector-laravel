<?php

declare(strict_types=1);

namespace RectorLaravel\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\ValueObject\Type\FullyQualifiedIdentifierTypeNode;
use Rector\Core\Rector\AbstractScopeAwareRector;
use Rector\NodeTypeResolver\TypeComparator\TypeComparator;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/** @see \RectorLaravel\Tests\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector\AddGenericReturnTypeToRelationsRectorTest */
class AddGenericReturnTypeToRelationsRector extends AbstractScopeAwareRector
{
    // Relation methods which are supported by this Rector.
    private const RELATION_METHODS = [
        'hasOne', 'hasOneThrough', 'morphOne',
        'belongsTo', 'morphTo',
        'hasMany', 'hasManyThrough', 'morphMany',
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];

    // Relation methods which need the class as TChildModel.
    private const RELATION_WITH_CHILD_METHODS = ['belongsTo', 'morphTo'];

    public function __construct(
        private readonly TypeComparator $typeComparator
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Add generic return type to relations in child of Illuminate\Database\Eloquent\Model',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE

                    ,
                    <<<'CODE_SAMPLE'
use App\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    /** @return HasMany<Account> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    public function refactorWithScope(Node $node, Scope $scope): ?Node
    {
        if (! $node instanceof ClassMethod) {
            return null;
        }

        if ($this->shouldSkipNode($node)) {
            return null;
        }

        $methodReturnType = $node->getReturnType();

        if ($methodReturnType === null) {
            return null;
        }

        $methodReturnTypeName = $this->getName($methodReturnType);

        if ($methodReturnTypeName === null) {
            return null;
        }

        if (! $this->isObjectType(
            $methodReturnType,
            new ObjectType('Illuminate\Database\Eloquent\Relations\Relation')
        )) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);

        // Don't update an existing return type if it differs from the native return type (thus the one without generics).
        // E.g. we only add generics to an existing return type, but don't change the type itself.
        if (
            $phpDocInfo->getReturnTagValue() !== null
            && ! $this->areNativeTypeAndPhpDocReturnTypeEqual(
                $node,
                $methodReturnType,
                $phpDocInfo->getReturnTagValue()
            )
        ) {
            return null;
        }

        $relationMethodCall = $this->getRelationMethodCall($node);
        if (! $relationMethodCall instanceof MethodCall) {
            return null;
        }

        $relatedClass = $this->getRelatedModelClassFromMethodCall($relationMethodCall);

        if ($relatedClass === null) {
            return null;
        }

        $classForChildGeneric = $this->getClassForChildGeneric($scope, $relationMethodCall);

        // Don't update the docblock if return type already contains the correct generics. This avoids overwriting
        // non-FQCN with our fully qualified ones.
        if (
            $phpDocInfo->getReturnTagValue() !== null
            && $this->areGenericTypesEqual(
                $node,
                $phpDocInfo->getReturnTagValue(),
                $relatedClass,
                $classForChildGeneric
            )
        ) {
            return null;
        }

        $genericTypeNode = new GenericTypeNode(
            new FullyQualifiedIdentifierTypeNode($methodReturnTypeName),
            $this->getGenericTypes($relatedClass, $classForChildGeneric),
        );

        // Update or add return tag
        if ($phpDocInfo->getReturnTagValue() !== null) {
            $phpDocInfo->getReturnTagValue()
                ->type = $genericTypeNode;
        } else {
            $phpDocInfo->addTagValueNode(new ReturnTagValueNode($genericTypeNode, ''));
        }

        return $node;
    }

    private function getRelatedModelClassFromMethodCall(MethodCall $methodCall): ?string
    {
        $argType = $this->getType($methodCall->getArgs()[0]->value);

        if ($argType instanceof ConstantStringType) {
            return $argType->getValue();
        }

        if (! $argType instanceof GenericClassStringType) {
            return null;
        }

        $modelType = $argType->getGenericType();

        if (! $modelType instanceof ObjectType) {
            return null;
        }

        return $modelType->getClassName();
    }

    private function getRelationMethodCall(ClassMethod $classMethod): ?MethodCall
    {
        $node = $this->betterNodeFinder->findFirstInFunctionLikeScoped(
            $classMethod,
            fn (Node $subNode): bool => $subNode instanceof Return_
        );

        if (! $node instanceof Return_) {
            return null;
        }

        $methodCall = $this->betterNodeFinder->findFirstInstanceOf($node, MethodCall::class);

        if (! $methodCall instanceof MethodCall) {
            return null;
        }

        // Called method should be one of the Laravel's relation methods
        if (! $this->doesMethodHasName($methodCall, self::RELATION_METHODS)) {
            return null;
        }

        if (count($methodCall->getArgs()) < 1) {
            return null;
        }

        return $methodCall;
    }

    /**
     * We need the current class for generics which need a TChildModel. This is the case by for example the BelongsTo
     * relation.
     */
    private function getClassForChildGeneric(Scope $scope, MethodCall $methodCall): ?string
    {
        if (! $this->doesMethodHasName($methodCall, self::RELATION_WITH_CHILD_METHODS)) {
            return null;
        }

        $classReflection = $scope->getClassReflection();

        return $classReflection?->getName();
    }

    private function areNativeTypeAndPhpDocReturnTypeEqual(
        ClassMethod $classMethod,
        Node $node,
        ReturnTagValueNode $returnTagValueNode
    ): bool {
        $phpDocPHPStanType = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
            $returnTagValueNode->type,
            $classMethod
        );

        $phpDocPHPStanTypeWithoutGenerics = $phpDocPHPStanType;
        if ($phpDocPHPStanType instanceof GenericObjectType) {
            $phpDocPHPStanTypeWithoutGenerics = new ObjectType($phpDocPHPStanType->getClassName());
        }

        $methodReturnTypePHPStanType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($node);

        return $this->typeComparator->areTypesEqual(
            $methodReturnTypePHPStanType,
            $phpDocPHPStanTypeWithoutGenerics,
        );
    }

    private function areGenericTypesEqual(
        Node $node,
        ReturnTagValueNode $returnTagValueNode,
        string $relatedClass,
        ?string $classForChildGeneric
    ): bool {
        $phpDocPHPStanType = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
            $returnTagValueNode->type,
            $node
        );

        if (! $phpDocPHPStanType instanceof GenericObjectType) {
            return false;
        }

        $phpDocTypes = $phpDocPHPStanType->getTypes();
        if ($phpDocTypes === []) {
            return false;
        }

        if (! $this->typeComparator->areTypesEqual($phpDocTypes[0], new ObjectType($relatedClass))) {
            return false;
        }

        $phpDocHasChildGeneric = count($phpDocTypes) === 2;
        if ($classForChildGeneric === null && ! $phpDocHasChildGeneric) {
            return true;
        }

        if ($classForChildGeneric === null || ! $phpDocHasChildGeneric) {
            return false;
        }
        return $this->typeComparator->areTypesEqual($phpDocTypes[1], new ObjectType($classForChildGeneric));
    }

    private function shouldSkipNode(ClassMethod $classMethod): bool
    {
        if ($classMethod->stmts === null) {
            return true;
        }

        $classLike = $this->betterNodeFinder->findParentType($classMethod, ClassLike::class);

        if (! $classLike instanceof ClassLike) {
            return true;
        }

        if ($classLike instanceof Class_) {
            return ! $this->isObjectType($classLike, new ObjectType('Illuminate\Database\Eloquent\Model'));
        }

        return false;
    }

    /**
     * @param array<string> $methodNames
     */
    private function doesMethodHasName(MethodCall $methodCall, array $methodNames): bool
    {
        $methodName = $methodCall->name;

        if (! $methodName instanceof Identifier) {
            return false;
        }
        return in_array($methodName->name, $methodNames, true);
    }

    /**
     * @return FullyQualifiedIdentifierTypeNode[]
     */
    private function getGenericTypes(string $relatedClass, ?string $childClass): array
    {
        $generics = [new FullyQualifiedIdentifierTypeNode($relatedClass)];

        if ($childClass !== null) {
            $generics[] = new FullyQualifiedIdentifierTypeNode($childClass);
        }

        return $generics;
    }
}

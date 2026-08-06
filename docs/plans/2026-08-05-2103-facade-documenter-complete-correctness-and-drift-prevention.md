# Facade Documenter Complete Correctness and Drift Prevention

Complete the Facade Documenter audit by fixing every remaining verified generator defect, preserving the richer Hypervel behavior already added on this branch, and making stale or invalid first-party facade metadata fail the normal test suite. The final generator should keep structured PHPDoc information structured until it renders output, emit only parseable and truthful method tags, publish source changes through Hypervel's checked atomic filesystem boundary, and cover every first-party facade without a second CI mechanism.

This work combines framework bug fixes with the current upstream Facade Documenter depth correction. It affects a developer CLI, generated facade PHPDoc, package metadata, and tests. It does not run in an application request, worker, or coroutine, so it adds no production throughput, memory, or lifecycle cost. Compatibility with faulty Hypervel-generated metadata is irrelevant; valid current capabilities and useful Laravel Facade Documenter behavior must remain intact.

## Goals

- Render native and PHPDoc unions, intersections, arrays, nullability, generics, conditionals, and template bounds with correct PHPDoc precedence.
- Preserve `MethodTagValueNode` metadata until final output so filtering never reparses method names from rendered type text.
- Parse supported docblock tags regardless of whether their content shares a line with the opening or closing marker.
- Resolve native and PHPDoc `self`, `parent`, and `static` names, including constant references and conditional targets, with PHP's actual lexical rules.
- Keep PHPDoc-only dynamic parameters compatible with Hypervel's native-nullability merge.
- Resolve every standard namespace-level PHP class import form used by supported source files without a regex pretending to parse PHP.
- Render every faithfully representable default value through phpdoc-parser's constant-expression AST, including nested arrays, enum cases, and non-finite floats.
- Fail clearly when Reflection cannot provide enough information to render a truthful object default.
- Never report a successful facade update after a failed or partial source-file write.
- Discover, lint, and parse every first-party facade through the normal PHPUnit suite.
- Remove stale implementation branches, parsing hacks, comments, imports, test names, and test fixture files as part of the correction.
- Keep the implementation direct: no runtime validation, parser replacement, source-expression reconstruction, cache, registry, retry loop, lock, or additional workflow.

## Package Shape and Baseline

`src/facade-documenter/facade.php` is an explicit short-lived CLI. It loads Composer, reflects each requested facade, resolves `@see` proxies and recursive `@mixin` classes, combines reflected methods with class-level `@method` tags, filters and normalizes them, generates a facade docblock, and either reports lint drift or updates the facade source.

The branch already contains and must preserve these Hypervel extensions:

- direct monorepo invocation without a Composer binary proxy;
- AST parsing of class-level `@method` tags;
- PHPStan `@param` and `@return` preference;
- generic preservation rather than upstream's intentional generic collapse;
- trait-owned import resolution;
- interface and enum resolution;
- constant, wildcard, `key-of`, and `value-of` inference;
- parameter-conditional preservation when its target remains exact;
- native-nullability merging;
- case-insensitive method deduplication;
- `ignoredFacadeDocumenterMethods()` support;
- global import shortening compatible with PHP CS Fixer;
- lint accumulation, verbose diagnostics, idempotence, and non-zero lint status.

The current repository contains 49 concrete first-party facades:

- 47 files under `src/support/src/Facades`, of which the base `Facade` is abstract and 46 are documentable;
- `Hypervel\Inertia\Inertia`;
- `Hypervel\Socialite\Socialite`;
- `Hypervel\Sentry\Facade`.

Parsing the committed docblocks before this work finds zero `InvalidTagValueNode` values across all discovered facades. That baseline matters: any invalid committed tag after regeneration is introduced by this change, not inherited output. Parse-back is only a syntax floor: wrong-but-valid output such as `\parent`, a mis-cased class name, a missing leading slash on `@see`, or `A|B[]` still parses and requires focused semantic assertions. The existing drift test covers only the 46 Support facades. The other three facades are stale under the current generator.

## References and Verified Research

### Local upstream

The direct upstream is `examples/laravel/facade-documenter` at local commit `7f5253b4476a0127df4d9a9dc2148deed15b7506`.

Current upstream PR #10 forwards recursion depth into `handleUnknownIdentifierType()` so a union-bounded template does not restart at outer depth. Hypervel is missing that correction. Upstream still loses DNF and array precedence, uses the partial import regex, renders defaults through JSON, and lacks Hypervel's structured conditional, constant, lint, ignore, and generic behavior. Port only the depth correction; do not replace the Hypervel implementation with upstream.

### PHPDoc parser behavior

Focused probes against the installed `phpstan/phpdoc-parser` 2.3.3 established:

- `A&B|C` is rejected, not interpreted as an intersection binding more tightly than a union;
- `A|B[]` and `A&B[]` parse with the wrong meaning when the intended type is `(A|B)[]` or `(A&B)[]`;
- `?A|null` is rejected;
- the current template-bound path can emit an unbalanced `B|(A|null` type;
- bare unions and intersections are valid inside parameter-conditional targets and branches;
- nullable nodes are valid inside unions, intersections, arrays, and other nullable nodes;
- `new \DateTimeImmutable` and `new \DateTimeImmutable()` are invalid method-tag defaults;
- `INF`, `NAN`, and an overflowing signed float such as `-1.0E+999` are valid defaults, while `-INF` is not;
- scalar, array, constant-fetch, and enum-case AST nodes render valid method-tag defaults;
- `ConstExprStringNode` correctly escapes apostrophes, slashes, control characters, and invalid byte sequences.

The generator should therefore use the installed AST as the output grammar, not add a second validation or escaping implementation in production.

### PHP reflection ownership

PHP resolves `self` and `parent` from a method's declaring class. `static` follows the selected late-bound source class. Reflection reports all three as non-built-in named types. The current `IdentifierTypeNode` branch resolves `self` from `getDeclaringClass()` and `static` from `sourceClass()`, but treats `parent` as a literal built-in. Native `parent` falls through to `\parent`. `resolveClassConstantClass()` separately resolves both `self` and `static` from `sourceClass()`, and conditional preservation rejects `parent`. These paths need one relative-name rule.

`ReflectionParameter::getDefaultValue()` retains scalar, null, array, and `UnitEnum` values faithfully. For an enum it retains the exact enum class and case name. PHP also permits `INF`, `NAN`, and `-INF` defaults; JSON encoding rejects each one, so they need explicit constant-expression representations. Reflection does not retain an arbitrary object's constructor expression, so no truthful method-tag default can be reconstructed for an object created with `new`.

### Filesystem publication

`Hypervel\Filesystem\Filesystem::get()` throws on failed reads. `Filesystem::replace()` writes a complete sibling temporary file, applies a requested mode, and atomically renames it over the target; it checks and throws for incomplete writes, chmod failures, and rename failures. The repository already uses the mode-preserving `chmod()` → `octdec()` → `replace()` pattern in `GeneratorCommand::replaceFile()` and several Foundation publishing commands.

This is the right boundary for a source generator. `replaceInFile()` detects failure but writes directly to the tracked path; an incomplete write can truncate a file containing unrelated uncommitted user work before it throws. Atomic replacement avoids that verified harm without package-owned filesystem machinery.

`src/facade-documenter/composer.json` currently receives Filesystem transitively through `hypervel/support`. Because the generator will import Filesystem directly, the split package must declare `hypervel/filesystem` directly.

### Import syntax

The current regex accepts only one unindented, single-line class import containing letters, digits, and backslashes. It misses underscores already present in repository imports, all grouped imports, comma-separated imports, multiline imports, and CRLF source. Extending the regex would remain a partial PHP parser.

Native tokenization can remain bounded to the source prefix before the reflected class. Namespace brace depth must count literal `{`, `T_CURLY_OPEN`, and `T_DOLLAR_OPEN_CURLY_BRACES`; interpolated strings close their special opening token with an ordinary `}`, so omitting those tokens corrupts depth. A closure capture uses `T_USE` before its body has increased brace depth and must also be rejected when the next meaningful token is `(`.

PHP import aliases and loaded class names are case-insensitive, but Composer's PSR-4 file lookup is case-sensitive on normal Linux filesystems. The import map therefore has two separate jobs: resolution must compare aliases case-insensitively, while generated shortening must preserve the alias exactly as written. Once a class, interface, or enum resolves, `ReflectionClass::getName()` provides its declared casing. This canonicalization prevents wrong-but-valid metadata, but it must not turn mis-cased, unloaded PSR-4 names into supported input through a filesystem scan or preloading scheme. PHPDoc class references must use correct PSR-4 casing.

PHP imports also replace the first segment of a qualified name, such as `use Vendor\Package as Package` with `Package\Result`. Name resolution is lexical and does not depend on whether the target is installed. The current `determineFqcn()` does both incorrectly: it compares the entire qualified name to the alias, and discards an imported name when the class is absent. Identifier, conditional-target, and constant-class callers then test the raw name before the resolved name, allowing a global class to shadow a same-namespace class.

`ReflectionClass::getName()` returns canonical names without a leading slash. Canonicalizing a fully qualified `@see` name without restoring the slash at the generated-docblock boundary would silently rewrite all first-party facade references. The lint command would agree with its own output and parse-back would accept the wrong-but-valid spelling, so this needs a focused semantic assertion.

The known-optional list contains one invalid inherited entry, `\GuzzleHttp\Psr7\RequestInterface`. That interface does not exist. Replacing it with `\Psr\Http\Message\RequestInterface` would still be dead: Facade Documenter requires Support, Support requires HTTP, and HTTP requires Guzzle and PSR HTTP Message. Keep only `\Pusher\Pusher`, which can genuinely be absent from a supported application install.

### All-facade discovery

Blindly deriving and autoloading every PHP file under the root `autoload.psr-4` mappings is unsafe. Composer maps files such as `src/coordinator/src/functions.php` both through PSR-4 directories and through `autoload.files`; treating that filename as a class includes it again and terminates on function redeclaration.

Discovery must first filter source files which declare a class matching their PSR-4-derived basename followed by `extends`, without assuming the parent alias. It can then safely autoload and use reflection as the authority: the class must be a non-abstract subclass of `Hypervel\Support\Facades\Facade`. This filter finds all current 49 facades, including `Hypervel\Sentry\Facade`, whose declaration is `class Facade extends BaseFacade`.

## Final Design

### 1. Parse docblock tags without line-layout assumptions

`resolveDocTags()` currently drops the first and last exploded lines positionally. That loses every supported tag written on the same line as `/**` or `*/`, including a one-line docblock. It can publish a method marked `/** @internal */`, miss a PHPDoc-only parameter, or fail to resolve a facade proxy or mixin.

Strip the opening `/**` prefix and closing `*/` suffix from the whole docblock before splitting it into lines. Normalize each remaining line with `ltrim($line, " \t*")`; remove the unintended backslash from the current charlist while accepting normal space or tab indentation. Then apply the existing exact tag-prefix filter and value extraction. This handles one-line, two-line, and conventional three-line layouts through one path without adding a docblock parser for simple tag collection.

Use one fixture run to prove single-line `@see`, `@mixin`, `@param`, `@internal`, and `@deprecated` tags all reach their existing consumers. The generated `@see` assertion must also retain its leading `\`, covering the output boundary described below.

### 2. Keep class-level methods as AST nodes

`resolveDocMethods()` currently transforms a `MethodTagValueNode` and immediately casts it to a string. Downstream code then tries to rediscover the method name and static marker from that rendered string. For `static int edit()`, `resolveName()` returns `int edit`; for a preserved conditional return such as `($value is A ? X : Y) edit(...)`, taking the last space before the first `(` can return an empty name.

Return the transformed `MethodTagValueNode` itself:

```php
function resolveDocMethods($class)
{
    // Resolve parameter and return nodes in place...

    return $method;
}
```

Carry `MethodTagValueNode|ReflectionMethodDecorator` through `resolveMethods()` and the filter pipeline:

- `resolveName()` returns `$method->methodName` for an AST method and `$method->getName()` for reflection;
- `isMagic()` always tests `resolveName()`;
- `isInternal()`, `isDeprecated()`, and `fulfillsBuiltinInterface()` return their current class-level-method result for `MethodTagValueNode` without treating it as Reflection;
- `conflictsWithFacade()` compares lowercase canonical names because PHP method identity is case-insensitive;
- ignored-method and uniqueness checks continue to lowercase `resolveName()`;
- `normaliseDetails()` leaves a `MethodTagValueNode` untouched;
- the final formatter reads `$method->isStatic` and casts the node to a string only when building the `@method` line.

Delete both rendered-string parsing hacks: `resolveName()`'s `after(' ')->before('(')` path and the main loop's `Str::startsWith($method, 'static ')` check.

### 3. Make the type renderer parent-aware and remove root wrapping

The current renderer wraps every depth-1 union/intersection, then `resolveTopLevelDocType()` removes parentheses with character-wise `trim($type, '()')`. The trim strips every consecutive parenthesis from either edge, so it can corrupt a grouped first member such as `(A&B)|C` as well as a grouped last member such as `B|(A|null)`. The two halves exist to undo each other and disagree during template recursion and nested composites.

Delete `resolveTopLevelDocType()`. `resolveDocMethods()`, `resolveDocParamType()`, and `resolveReturnDocType()` should call `resolveDocblockTypes()` directly. Keep `$depth` only so nested `UnresolvableType` failures rethrow to the outer call and produce one diagnostic.

Port upstream's depth correction:

```php
return handleUnknownIdentifierType($method, $typeNode, $depth);

// Inside handleUnknownIdentifierType...
$resolved = resolveDocblockTypes($method, $boundTemplateType, $depth);
```

Prefer PHPStan template tags consistently:

```php
$boundTemplateType = collect([
    ...$docblock->getTemplateTagValues('@phpstan-template'),
    ...$docblock->getTemplateTagValues(),
])->firstWhere('name', $typeNode->name)?->bound;
```

Type `$depth` as `int` on both changed functions. Apply parentheses only at a parent boundary that requires them. One structural helper should flatten union and nullable nodes without splitting rendered text:

```php
function resolveDocblockUnionMembers($method, $typeNode, int $depth): array
{
    if ($typeNode instanceof UnionTypeNode) {
        $members = [];

        foreach ($typeNode->types as $node) {
            foreach (resolveDocblockUnionMembers($method, $node, $depth + 1) as $member) {
                if (! in_array($member, $members, true)) {
                    $members[] = $member;
                }
            }
        }

        return in_array('mixed', $members, true) ? ['mixed'] : $members;
    }

    if ($typeNode instanceof NullableTypeNode) {
        $members = resolveDocblockUnionMembers($method, $typeNode->type, $depth + 1);

        if (in_array('mixed', $members, true)) {
            return ['mixed'];
        }

        if (! in_array('null', $members, true)) {
            $members[] = 'null';
        }

        return $members;
    }

    $type = resolveDocblockTypes($method, $typeNode, $depth + 1);

    return [$typeNode instanceof IntersectionTypeNode ? "({$type})" : $type];
}

// Array: (A|B)[] and (A&B)[]
$type = resolveDocblockTypes($method, $typeNode->type, $depth + 1);

return $typeNode->type instanceof UnionTypeNode
    || $typeNode->type instanceof IntersectionTypeNode
    || $typeNode->type instanceof NullableTypeNode
        ? "({$type})[]"
        : "{$type}[]";
```

The complete rules are:

- union and nullable: structurally flatten their AST members, collapse any union containing `mixed` to `mixed`, deduplicate exact members, add one `null` for nullable, parenthesize intersection members, and join with `|` without outer parentheses;
- intersection: deduplicate members, parenthesize union or nullable children, and join with `&` without outer parentheses;
- conditional: retain its own required outer parentheses; it needs no extra wrapper when used as a union child;
- array: parenthesize union, intersection, or nullable operands before `[]`; an already-parenthesized conditional remains valid;
- native `ReflectionUnionType`: parenthesize every `ReflectionIntersectionType` member;
- generics: retain nested unions inside `<...>` without unnecessary outer parentheses;
- class-level top-level unions: remain `A|B`, matching the existing regression and normal PHPDoc style.

Both the `UnionTypeNode` and `NullableTypeNode` branches in `resolveDocblockTypes()` should delegate to `resolveDocblockUnionMembers()` and join the returned members with `|`. Remove the nullable branch's direct `'?' . resolveDocblockTypes(...)` rendering; structural nullable output is always the canonical `T|null` spelling before a parent intersection or array decides whether to wrap it.

When rendering a preserved parameter conditional, pass `$depth + 1` into the target-type call just as the two branches do. Resetting it to the default depth lets a nested target failure print a partial diagnostic and return `null` inside an otherwise successful render.

Template identifiers can resolve to composite bounds even though their original node remains an `IdentifierTypeNode`. Parenthesization must therefore account for both the original AST node and the rendered result. Rename the existing rendered-type scanner to `splitTopLevelTypes(string $type, string $separator = '|'): array` and reuse it rather than adding another parser. It must ignore separators inside generics and parentheses.

Use that scanner at the three parent boundaries:

- union members: split a template-expanded top-level union into members, deduplicate them through the existing union path, and parenthesize any member containing a top-level intersection;
- intersection members: parenthesize any child rendering that contains a top-level union;
- array operands: parenthesize any rendering containing a top-level union or intersection before appending `[]`.

Keep the scanner in `flattenConditionalBranches()` and `mergeDocblockTypeWithNativeNullability()`, which also combine already-rendered strings. Keep the merge function's independent `mixed` guard. Do not patch it to recognize leading `?`; canonical `|null` output removes the second spelling.

### 4. Resolve relative class names from the correct owner

Add one relative-name helper shared by PHPDoc identifiers, native named types, constant fetches, and conditional targets:

```php
return match (strtolower($name)) {
    'self' => $method->getDeclaringClass()->getName(),
    'static' => $method->sourceClass()->getName(),
    'parent' => ($parent = $method->getDeclaringClass()->getParentClass()) === false
        ? null
        : $parent->getName(),
    default => null,
};
```

Check relative names before generic built-ins everywhere. Remove `self`, `parent`, and `static` from `isBuiltIn()`. A PHPDoc `parent` with no declaring parent resolves to `mixed`, and `canPreserveConditionalTarget()` returns false for it. Valid `self` and `static` targets remain preserved. The conditional formatter must still render its target through `resolveDocblockTypes()` so no raw relative name is rebound to the facade.

Remove `parent` from `canPreserveConditionalTarget()`'s explicit reject list. Resolve it through the shared relative-name helper before checking built-ins or named classes; a declaring class with no parent remains unpreservable.

`resolveClassConstantClass()` should use the same helper before resolving a named or imported class. `resolveConstFetchType()` and `resolveKeyOrValueOf()` already use that boundary and need no separate ownership branches.

Use a second small helper for canonical existing class names:

```php
function resolveCanonicalClassName(string $name): ?string
{
    if (! class_exists($name) && ! interface_exists($name)) {
        return null;
    }

    return (new ReflectionClass($name))->getName();
}
```

`class_exists()` already covers enums. Use this helper from `determineFqcn()`, PHPDoc identifier rendering, constant-class resolution, and conditional-target preservation rather than repeating class/interface/enum checks or using exceptions for normal unresolved template names. Prefix the canonical name with `\` only at output boundaries.

For the three PHPDoc type consumers, try the lexically resolved name first and the raw identifier second as an explicit global-class fallback. This preserves the tool's useful leniency for a missing import such as `Closure` without letting global `Countable`, `Stringable`, `Attribute`, or `ArrayObject` shadow a real class in the proxy namespace. `resolveProxies()` and mixin resolution use only the lexical result; class references in `@see` and `@mixin` should not guess a global fallback. When rendering the resolved proxy collection back into `@see` lines, restore exactly one leading `\`. Internal canonical names remain unprefixed until this output boundary.

### 5. Complete the dynamic-parameter abstraction

Hypervel's nullability merge evaluates both the PHPDoc and native type arguments. A trailing PHPDoc-only parameter is represented by `DynamicParameter`, which lacks `getType()`, so the current branch crashes before it can merge.

Add the missing reflection-compatible method and fully type the touched helper class:

```php
class DynamicParameter
{
    public function __construct(private string $definition)
    {
    }

    public function getType(): null
    {
        return null;
    }

    // getName(): string, isOptional(): bool, isVariadic(): bool,
    // isDefaultValueAvailable(): bool, getDefaultValue(): null
}
```

Do not special-case `DynamicParameter` in `normaliseDetails()`. The internal parameter abstraction owns the method the caller needs. Preserve upstream's output for a doc-only `@param int $extra`: `int $extra = null`. This correction fixes the eager-evaluation crash without silently redesigning upstream's dynamic parameter convention.

The focused fixture must use a conventional multiline docblock which lists every native parameter in order before the trailing PHPDoc-only parameter. `resolveParameters()` intentionally follows upstream's positional convention and skips the number of native parameters before creating dynamic parameters; it does not match tags by name.

### 6. Replace import regex parsing with bounded native tokenization

`resolveClassImports()` should read only the source prefix before `$class->getStartLine()`, tokenize it with native PHP tokens, and return the same alias-to-leading-backslash-FQCN collection contract as today.

The scanner should maintain:

- the active namespace name;
- the current brace depth;
- the namespace's top-level brace depth (`0` for semicolon namespaces, `1` inside a bracketed namespace);
- the imports collected for the active namespace, reset at each `T_NAMESPACE`;
- an index over significant tokens so comments and whitespace do not affect grammar.

At `T_USE`:

1. If the next significant token is `(`, skip the closure capture.
2. If current depth is not the active namespace's top-level depth, skip a trait or body-level use.
3. Read through the terminating semicolon.
4. Skip a statement-level `use function` or `use const`.
5. Parse comma-separated entries and a grouped prefix.
6. Within a grouped import, skip individual `function` and `const` members while retaining class members.
7. Preserve each alias exactly as written; otherwise use the imported class basename.

Count all three opening-brace forms while scanning:

```php
if ($token->text === '{'
    || $token->id === T_CURLY_OPEN
    || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
    ++$braceDepth;
}

if ($token->text === '}') {
    --$braceDepth;
}
```

This prevents a preceding interpolated string from moving the apparent namespace depth and either admitting trait imports or dropping namespace imports. Stop at the reflected class so later classes and body tokens remain irrelevant. Do not add a parser dependency, multiline regex, or memoization; a full facade lint currently takes seconds and no import-resolution performance problem exists.

Keep the original alias as the collection key because `shortenImportedGlobalTypes()` uses that key as generated replacement text. `determineFqcn()` should resolve names lexically, independent of existence:

1. preserve a leading `\` fully qualified name;
2. resolve a leading `namespace\` from the source namespace;
3. compare the first segment to import aliases case-insensitively, replace a match with the imported FQCN, and append any remaining segments;
4. otherwise prefix the source namespace;
5. canonicalize through `resolveCanonicalClassName()` when Reflection can resolve the result, or return the lexically correct unresolved name unchanged.

This keeps imported absent types available to the narrow known-optional boundary and gives missing `@see` errors the correct namespace. Apply the same canonicalization to the explicit raw global fallback so wrong-but-valid casing is never emitted after Reflection has resolved a type. Do not scan the filesystem or preload classes to rescue a mis-cased, unloaded PSR-4 reference; correct source casing is required.

Remove the invalid `\GuzzleHttp\Psr7\RequestInterface` optional entry and keep only `\Pusher\Pusher`. Do not add an extension hook or configurable optional-type registry without a verified use and understood public API. No mechanism-free absent-optional regression exists in the components environment because Pusher is installed for development; do not add a fake production entry or autoloader seam solely to exercise the one-value predicate.

### 7. Render defaults through constant-expression nodes

Replace the remaining JSON and quote-replacement path with a recursive helper returning `ConstExprNode`:

```php
function resolveDefaultValueNode(mixed $value, string $context): ConstExprNode
{
    return match (true) {
        is_string($value) => new ConstExprStringNode(
            $value,
            preg_match('/[\x00-\x1F\x7F]/', $value) === 1
                ? ConstExprStringNode::DOUBLE_QUOTED
                : ConstExprStringNode::SINGLE_QUOTED,
        ),
        is_int($value) => new ConstExprIntegerNode((string) $value),
        is_float($value) && is_nan($value) => new ConstFetchNode('', 'NAN'),
        $value === INF => new ConstFetchNode('', 'INF'),
        $value === -INF => new ConstExprFloatNode('-1.0E+999'),
        is_float($value) => new ConstExprFloatNode(
            json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
        ),
        $value === true => new ConstExprTrueNode,
        $value === false => new ConstExprFalseNode,
        $value === null => new ConstExprNullNode,
        $value instanceof UnitEnum => new ConstFetchNode('\\' . $value::class, $value->name),
        is_array($value) => resolveDefaultArrayNode($value, $context),
        default => throw an actionable RuntimeException,
    };
}
```

For arrays:

- recurse for every value, including enum cases;
- use `ConstExprArrayNode` and `ConstExprArrayItemNode`;
- omit keys only when `array_is_list($value)` is true;
- otherwise render every integer or string key through the same scalar node boundary;
- preserve nested arrays and float spelling.

The non-finite branches must precede the general float branch. `json_encode()` rejects `INF`, `NAN`, and `-INF` with a bare `JsonException`, which loses the generator context. phpdoc-parser accepts `INF` and `NAN` as unqualified constant fetches but rejects `-INF`; `-1.0E+999` parses and evaluates to the same negative-infinity float. Add one short source comment on that negative branch so the intentional spelling remains clear. Do not add generated comments or route representable scalar values through the object-default failure.

One `UnitEnum` branch covers backed and unbacked enums. Always emit the enum case name, never a backed value, because a `Status` parameter defaults to `Status::Active`, not to its backing string. Prefix the enum class with `\`; the generator's `shortenImportedGlobalTypes()` pass can then reduce a global imported enum such as `\SortDirection::Ascending` consistently with generated types before PHP CS Fixer runs.

Keep upstream's exact `493` to `0755` presentation correction because Reflection exposes those two Filesystem defaults as decimal. Do not generalize octal rendering to every integer parameter named `$mode`: `$mode` is also a normal domain name for non-permission values such as `MB_CASE_FOLD`, and a generic name-based rewrite would assign those values misleading octal spelling. Remove the `DateTimeInterface` branch. Arbitrary object constructor expressions cannot be recovered or represented by phpdoc-parser's method-tag grammar.

Keep `resolveDefaultValue(array $parameter, string $facade): string` as the parameter-aware entry point. It retains the exact name-and-value-gated `0755` correction, builds one constant context string from the facade, declaring class, declaring method, and parameter name, and otherwise casts `resolveDefaultValueNode($parameter['default'], $context)` to a string. `resolveDefaultValueNode(mixed $value, string $context)` and `resolveDefaultArrayNode(array $value, string $context)` thread only that message context through recursion. Their scalar rendering rules receive no parameter record and remain value-driven.

When an object default cannot be represented, throw a `RuntimeException` that names:

- the facade being generated;
- the proxy declaring class and method;
- the parameter name;
- the object's class;
- `ignoredFacadeDocumenterMethods()` as the existing way to exclude a method that cannot be documented.

Carry the declaring class once on the normalized method entry created by `normaliseDetails()`, beside the existing method name. Add `$facade` to the final formatter closure's capture list, capture the normalized method in its nested parameter formatter, and pass the facade name, method declaring class, method name, and parameter name into `resolveDefaultValue()`. Build the invariant message context once there; recursive array-node calls pass the same string, and the throw appends only the current object's class and ignore-hook guidance. Declaring class and method are method metadata, so do not duplicate them on every parameter or add reflection-owner shims to `DynamicParameter`. Do not use process globals, add an injectable renderer, or introduce a context object for four strings already available in the existing pipeline. Leave the existing marker for an optional internal reflection parameter with no available default unchanged. It is unreachable for normal userland proxy methods and is not the same failure as a known object value whose source expression has been lost.

### 8. Publish source updates atomically and preserve modes

Create one `Filesystem` instance for the CLI run and capture it in the facade loop. Replace raw unchecked I/O with:

```php
$path = $facade->getFileName();
$contents = $filesystem->get($path);
$permissions = $filesystem->chmod($path);

if ($permissions === false) {
    throw new RuntimeException("Unable to determine the permissions for [{$path}].");
}

$filesystem->replace(
    $path,
    str_replace($facade->getDocComment(), $docblock, $contents),
    octdec($permissions),
);
```

There is no `exists()` or `getFileName() === false` branch: an internal class cannot supply a docblock, so it has no `@see`, produces no proxies, and exits through the existing skip path. Let the typed Filesystem boundary fail natively if that invariant is ever violated. The current no-docblock `false` replacement needle is unreachable for the same reason.

Add `hypervel/filesystem: ^0.4` to the split manifest. The Facade Documenter metadata test should assert only its exact direct requirement set: `php`, `composer-runtime-api`, `phpstan/phpdoc-parser`, `hypervel/filesystem`, and `hypervel/support`.

Root and split dependency consistency belongs to one repository-wide test at `tests/Composer/PackageManifestConsistencyTest.php`. Walk every `src/*/composer.json` `require` and `require-dev` entry. Skip only `php`, whose split `^8.4` constraint deliberately differs from root `>=8.4`. Every `hypervel/*` dependency must appear in root `replace` as `self.version`; every other dependency, including extensions, must appear in root `require` or `require-dev` with the exact same constraint. Failure messages must name both the split package and dependency. Do not inspect `suggest`, `provide`, or `conflict`, and do not add an allowlist, exception table, shared base class, or workflow.

The same Composer test should own two other uniform structural conventions without expanding into package-specific prose, authors, descriptions, keywords, or the optional `type: library` spelling:

- every path declared under root or split `autoload` and `autoload-dev` `psr-4`, `files`, and `classmap` must exist relative to its manifest;
- every split `support` block must exactly match the root block, which points source and issues to `hypervel/components`.

Remove the premature `Hypervel\Boost\` PSR-4 entries from root and split metadata because Boost currently contains documentation only. Keep the all-facade test's local directory assertion so a broken path reports clearly before iterator construction. Correct Boost's support URLs and add the standard support block to Fortify, Passkeys, and Prompts. Add Prompts' missing `config.sort-packages: true`; do not normalize the semantically equivalent presence or absence of `type: library`. Run `composer dump-autoload` after the mapping removal.

The completed `src/boost/docs-ported.md` ledger contains every current Boost documentation page except the intentionally irrelevant `octane.md`, with no stale entries. Delete it rather than shipping or relocating a completed internal checklist.

Correct the existing drift exposed by that invariant:

- set `brick/math` to `^0.17` in the Database and Validation split manifests;
- set `nesbot/carbon` to `^3.13.1` in the Console split manifest;
- raise root `symfony/polyfill-php85` to `^1.36`;
- declare `phpoption/phpoption: ^1.9`, `psr/http-client: ^1.0`, `symfony/event-dispatcher: ^8.1`, `ext-ctype: *`, and `ext-sockets: *` in root `require` through Composer;
- move Engine's `ext-sockets: *` entry from `suggest` to `require`, because Engine uses `SOCKET_ETIMEDOUT` unconditionally on supported socket error paths;
- remove duplicated root cross-checks from the Auth, Broadcasting, Http, Mail, Notifications, and Facade Documenter metadata tests while retaining package-specific dependency, provider, suggestion, and discovery assertions.

Several other split packages call Ctype functions without declaring `ext-ctype`. That is a separate source-to-extension dependency audit across all split packages, not part of root-to-split consistency. Do not partially add those declarations here.

### 9. Make the ignore hook fail fast when misdeclared

`ReflectionMethod::setAccessible()` has had no effect since PHP 8.1; remove it. Remove the guard that silently returns an empty ignore list for a non-static `ignoredFacadeDocumenterMethods()` method. Invoke the hook with `null` as today and let Reflection report a non-static declaration error. A broken opt-out hook must not silently generate methods its author intended to exclude.

No custom exception wrapper is needed. The package exception handler already prints the exact failure and exits non-zero.

### 10. Give facade fixture files deterministic ownership

`FacadeDocumenterTestCase::writeAppFile()` writes into the Testbench runtime's shared `BASE_PATH/app` clone. Track the top-level fixture path created from every relative fixture path, create directories without `@mkdir`, and delete each tracked file or directory in `tearDown()` before calling `parent::tearDown()` in a `finally` block.

The cleanup must remove only fixture roots recorded by this test case. It must not delete `BASE_PATH/app` or the skeleton's existing `Console`, `Exceptions`, `Http`, `Models`, or `Providers` directories. For each recorded root, use a direct `is_dir()` choice between `Filesystem::deleteDirectory()` and `Filesystem::delete()` rather than adding recursive test-only deletion code.

All current fixtures use test-owned top-level namespaces such as `Generic`, `Imports`, and `DefaultValues`, so one recorded root can clean all files a test created beneath it. No separate test-only abstraction or unused root-file fixture is needed.

### 11. Correct the real error-test contract

Rename the false `testFacadeWithUnresolvableSeeExitsCleanlyWithoutStackTrace()` test to state the actual behavior: an unresolvable proxy exits non-zero through the package exception handler and reports the missing class. Assert the exact missing FQCN in the `ReflectionException` message. Remove the comment which says a stack trace is both absent and expected.

Keep the stack trace. This is an explicit developer CLI and the trace is useful.

### 12. Discover, lint, and parse every first-party facade

Move `tests/Support/FacadeDocblocksTest.php` to `tests/FacadeDocumenter/FacadeDocblocksTest.php` and change its namespace from `Hypervel\Tests\Support` to `Hypervel\Tests\FacadeDocumenter`; after this change it is a Facade Documenter package invariant, not a Support-only test. Keep it on `Hypervel\Tests\TestCase`. It writes no app fixtures and does not need Testbench's runtime skeleton or fixture-cleanup lifecycle. Its existing `dirname(__DIR__, 2)` repository-root lookup remains correct after the move.

Read only the root `composer.json` `autoload.psr-4` map and normalize each path value to a list. Do not walk `autoload-dev.psr-4`; test and Workbench namespaces cannot contain first-party production facades. For each `.php` file under the production source roots:

1. derive the expected class name from the PSR-4 prefix and relative file path;
2. cheaply require the source to contain a declaration matching `class <derived basename> ... extends`, without naming the parent alias;
3. only then autoload the derived class;
4. reflect it and retain it when `isSubclassOf(Facade::class)` and `! isAbstract()`;
5. sort and deduplicate the resulting class list.

The prefilter is required for correctness, not just speed. It prevents autoloading Composer `files` entries as invented classes and avoids function redeclaration fatals. Reflection, not the text filter's parent name, decides whether a candidate is a facade.

The test class should enforce two independent invariants:

1. Run the generator once with `--lint` and all discovered facade classes; assert exit zero and include combined output on failure.
2. Parse every reflected facade docblock with the installed phpdoc-parser and assert no tag value is an `InvalidTagValueNode`. Include the facade and offending rendered tag in the assertion message.

Do not hardcode `49`; that number is the verified implementation baseline, not a future API limit. Do not treat parse-back as semantic proof: wrong precedence, relative ownership, and class casing can remain syntactically valid. Focused exact-output tests own those contracts. Do not add an explicit workflow list or another CI job. The normal test workflow already runs this PHPUnit test.

### 13. Regenerate and review metadata only after source behavior is green

After all focused renderer tests pass, run the parse-back invariant against the currently committed 49 facades before changing any generated docblock. It must remain green with zero invalid tag values.

Then run the corrected generator across all discovered facades. Review every generated method change against the owning proxy, native signature, PHPDoc, contract, and imports. Do not accept generated output mechanically. At minimum, expect and verify:

- `Date`: the 18 nullable-shorthand occurrences across 10 `@method` lines become canonical `|null` metadata; existing source unions that already spell null first keep their member order, and any additional Date drift must be traced;
- `Request`: `float()` preserves the proxy's `0.0` default instead of emitting `0`;
- `Sentry`: `getIntegration()` retains `IntegrationInterface|null` through `@phpstan-template` instead of the current generator's `mixed|null`; the independently stale `withScope()` line refreshes from committed `mixed|void` to the current generator's coherent `mixed` type, but is not evidence for the template fix;
- `Inertia` and `Socialite`: existing stale metadata is refreshed against their current proxies.

Any additional drift must be traced before accepting it. Finish by rerunning both all-facade lint and parse-back checks.

### 14. Remove stale code and complete touched test typing

The final source must not retain:

- `resolveTopLevelDocType()`;
- root union/intersection wrapping and character-wise parenthesis trimming;
- direct `'?' . resolveDocblockTypes(...)` nullable rendering;
- string-backed `@method` name/static parsing;
- the one-line import regex;
- positional first/last-line removal in `resolveDocTags()` and its unintended backslash-trimming charlist;
- JSON quote replacement for default arrays;
- the invalid `DateTimeInterface` default branch;
- unchecked raw facade file writes;
- the no-op `setAccessible(true)` call;
- the silent non-static ignore-hook guard;
- loose `in_array()` calls in the substantially rewritten executable;
- the misspelled `$encoutered` parameter and local in `resolveDocMixins()`;
- the nonexistent `\GuzzleHttp\Psr7\RequestInterface` optional dependency entry;
- recursion-depth resets inside nested type rendering;
- loose `Collection::contains()` and `Collection::unique()` comparisons;
- stale comments or imports tied to those paths.

Make every `in_array()` call in `facade.php` strict, including CLI flags, built-in types, optional dependencies, and built-in interface methods. Replace the remaining loose Collection name/type deduplication and membership checks with `uniqueStrict()` and `containsStrict()` in method filtering, constant inference, conditional flattening, native-nullability merging, mixin cycle detection, and facade conflict checks. Every operand is a method name, class name, or rendered type string, so this aligns comparison semantics without changing the supported values. Correct `$encoutered` to `$encountered` in the function signature, docblock, and recursion.

Add `: void` to every remaining Facade Documenter test method, including methods in files not otherwise changed by a behavior regression. This is a complete package audit and the repository requires typed test methods. The currently affected files are:

- `CaseInsensitiveDedupeTest.php` (renamed to match its broader filtering coverage);
- `ConstFetchResolutionTest.php`;
- `DocblockNativeNullabilityMergeTest.php`;
- `GenericPreservationTest.php`;
- `GracefulDegradationTest.php`;
- `IdempotenceTest.php`;
- `IgnoredMethodsTest.php`;
- `ImportResolutionTest.php`;
- `MixedNativeNullableTest.php`;
- `RelativeTypeResolutionTest.php` (renamed from `NullableSelfStaticTest.php`);
- `StaticPrefixGuardTest.php`;
- `TraitImportSourceTest.php`;
- `WrapperCollapseTest.php`.

Do not expand this into native typing of every untouched global function in the upstream-style executable. New and changed helpers should receive correct native types where the existing mixed AST/reflection boundary permits them without awkward unions or suppressions.

### 15. Correct the InteractsWithData Stringable contract

`InteractsWithData::str()` and `string()` return `Hypervel\Support\Stringable`, but the trait currently imports PHP's global `Stringable` interface for their native return types. That makes the public contract too weak and causes the Request facade to advertise only the minimal `__toString()` interface.

The trait also intentionally accepts any globally stringable object while coercing string-backed enum values. Keep both concepts explicit:

```php
use Hypervel\Support\Stringable;
use Stringable as BaseStringable;
```

Use `Stringable` for the two return types and `BaseStringable` in `normalizeEnumValue()`. No comment or compatibility shim is needed; the aliases state the distinction and preserve existing runtime behavior.

Add source-owning regression coverage which reflects both methods and asserts the exact Hypervel return type, then passes an anonymous object implementing global `Stringable` through `enum()` and confirms string-backed enum coercion still succeeds. Existing Fluent and Request tests already cover the broader `enum()` and `enums()` behavior, so do not duplicate that matrix. Regenerate only the Request facade lines produced by these signatures; other global `Stringable` metadata remains unchanged.

## Test Plan

### Docblock tag layouts

Create `DocTagParsingTest.php` with one coherent fixture graph and one generator run. Preserve a facade and proxy using single-line `@see`, `@mixin`, `@internal`, `@deprecated`, and trailing `@param` tags. Add a second facade and proxy using tab-indented multiline `@see`, `@mixin`, `@internal`, and `@param` tags, reuse the same mixin class, and pass both facades to the same subprocess. Assert both proxies and mixins resolve, filtered methods stay absent, PHPDoc-only parameters appear, and generated `@see` references retain exactly one leading `\`.

### Structured method filtering

Rename `CaseInsensitiveDedupeTest.php` to `ClassDocblockMethodFilteringTest.php` and use one focused class-level-method fixture to assert:

- static and non-static AST tags retain their existing static marker behavior;
- a preserved conditional return type does not corrupt method identity;
- `__*` class-level methods are filtered;
- methods conflicting with a real facade method are filtered case-insensitively;
- names returned by `ignoredFacadeDocumenterMethods()` are filtered;
- duplicate casings collapse to one method.

This is one pipeline test, not six independent fixtures.

Extract the generated class docblock first and assert that extraction succeeds. Run every positive, negative, and duplicate-name assertion against that docblock rather than the whole fixture file so the facade's ignore hook and real conflicting method cannot satisfy or break metadata assertions.

### Type rendering

Create `TypePrecedenceTest.php` covering exact generated output and parser acceptance for:

- docblock `(Alpha&Beta)|null`;
- native `(Alpha&Beta)|null`;
- a grouped first member `(Alpha&Beta)|Gamma`, proving character-wise top-level trim no longer corrupts a leading group;
- `(Alpha|Beta)[]`;
- `(Alpha&Beta)[]`;
- `(?Alpha)|null` canonicalized to one `null` member;
- `(?Alpha)&Beta` rendered as `(Alpha|null)&Beta`;
- `?(Alpha&Beta)` rendered as `(Alpha&Beta)|null`;
- `?(Alpha|Beta)` rendered as `Alpha|Beta|null`;
- `(?Alpha)[]` rendered as `(Alpha|null)[]`;
- nullable PHPDoc plus nullable native type without `?T|null`;
- nested generic unions;
- a conditional adjacent to unions/intersections.

Use one proxy fixture with the 13 typed methods above, shared `Alpha`, `Beta`, and `Gamma` types, one facade, and one generator subprocess. Assert every exact method line, then parse the produced facade docblock once. These are independent successful renderings with no failure path or mutable interaction, so separate subprocesses would add repetition without proving another boundary.

Extend `GenericPreservationTest.php` with the exact upstream PR #10 union-bounded-template reproducer and assert balanced output. Extend `PhpstanTagResolutionTest.php` with `@phpstan-template` preference and a normal `@template` fallback.

The upstream template reproducer must not declare a native return type; a native `mixed` return would route the result through native-nullability merging and accidentally deduplicate the broken template rendering. Add five more template-bound cases to `TypePrecedenceTest`: nested nullable null, array of a union bound, array of an intersection bound, union with an intersection bound, and intersection with a union bound. Leave the nested-nullable method without a native return type and explain in one line that a native type would mask the template regression. The upstream reproducer remains the sole duplicate-member case.

### Relative type ownership

Rename `NullableSelfStaticTest.php` to `RelativeTypeResolutionTest.php`. Use an inherited three-level hierarchy to assert native and PHPDoc `self`, `parent`, and `static` parameters and returns resolve to the declaring class, declaring parent, and selected source class respectively. Preserve the existing nullable-self regression. Add one parameter-conditional target using `parent` and assert the condition remains present with its resolved canonical class rather than flattening or emitting the literal keyword.

### Constant ownership

Extend `ConstFetchResolutionTest.php` with a three-level hierarchy. Put different value types on constants in the grandparent, declaring parent, and selected child, then assert inherited `self::`, `parent::`, and `static::` results separately. Existing direct/wildcard/`key-of`/`value-of` tests continue to cover the shared callers; do not duplicate their entire matrix.

Add one shared shadowing fixture whose namespace defines a `DateTime` class with a scalar `VALUE` constant and array `MAP` constant. Assert that unqualified `DateTime::VALUE`, `key-of<DateTime::MAP>`, and `value-of<DateTime::MAP>` use that lexical class instead of global `\DateTime`, while explicitly qualified `\DateTime::ATOM` still uses the global class. This proves constant resolution follows the same lexical-first order as identifiers and preserves the leading slash passed into `determineFqcn()`.

### Dynamic parameters

Create `DynamicParameterTest.php` with a method that has one native parameter. Its conventional multiline PHPDoc must list that native parameter first and then one trailing parameter absent from the native signature. Assert generation succeeds and emits the exact native and docblock types plus the upstream-compatible `= null` default for the trailing parameter.

### Import resolution

Extend `ImportResolutionTest.php` with a bounded matrix covering:

- underscore-containing imported class names;
- comma-separated class imports;
- grouped imports and aliases;
- an imported alias used as the first segment of a qualified name;
- multiline grouped imports;
- one bracketed namespace;
- CRLF source;
- statement-level `use function` and `use const` ignored;
- mixed grouped class/function/constant members classified individually;
- closure capture skipped;
- a preceding class with a trait import skipped;
- an interpolated string in that preceding body, proving special curly tokens do not corrupt depth;
- a mis-cased imported alias resolving to the declaration's casing;
- a resolvable mis-cased same-namespace name resolving to the declaration's casing;
- a global imported type such as `Closure` shortening with its original alias casing;
- a same-namespace class whose short name matches a global class, with the namespaced class winning;
- an unimported global `Closure` retaining the explicit last-resort fallback;
- an imported missing `@see` alias reporting the imported FQCN;
- an unqualified missing `@see` reporting the source-namespaced FQCN.

Put the pure scanner grammar forms—underscore names, comma-separated imports, multiline grouped imports, `use function`, `use const`, and mixed grouped members—into one fixture and one subprocess run with several generated-method assertions. Keep separate fixture runs only where behavior or source layout genuinely differs: bracketed namespaces, CRLF, closure capture, trait skipping, interpolated-string depth, alias casing, same-namespace/global shadowing, global fallback, and missing-class reporting. Split those cases between class-level `@see` and method PHPDoc resolution without repeating every syntax form in both paths.

### Default rendering

Extend `DefaultValueTest.php` to assert exact and parser-valid output for:

- apostrophes, double quotes, backslashes, control characters, and non-ASCII strings;
- list, associative, and nested arrays;
- integer and string array keys;
- booleans and null;
- integers, `1.0`, `INF`, `NAN`, and `-INF`;
- backed and unbacked enum cases, including an enum nested in an array;
- the existing `0755` correction.

Cover both a top-level object default and an object nested inside an array default. Each needs its own generator subprocess because generation stops at the first exception; putting both methods in one failing run would execute only one path. For each run, assert non-zero exit, unchanged facade source, and a message containing the facade, declaring class and method, parameter, object class, and ignore-hook guidance. The direct case proves `resolveDefaultValue()` delegates with context, while the nested case proves recursive array rendering retains it.

### Publication and dependency boundary

Create `FilePublicationTest.php`. Give a fixture facade a non-default mode such as `0640`, run generation, and assert both the updated docblock and exact preserved mode. Do not force OS failure modes or add a source injection seam; Filesystem owns and already tests its internal failure branches.

Create `PackageMetadataTest.php` and assert the exact direct requirement set contains `php`, `composer-runtime-api`, `phpstan/phpdoc-parser`, `hypervel/filesystem`, and `hypervel/support`. Root consistency is deliberately owned by the repository-wide Composer test instead of being duplicated here.

### Composer manifest consistency

Create `tests/Composer/PackageManifestConsistencyTest.php`. Walk every split manifest's `require` and `require-dev` entries and enforce the root consistency rule from section 8. Also validate every declared autoload path and compare each split support block with the root. Run it red against the verified constraint mismatches, missing root declarations, dead Boost mappings, and support metadata drift; apply the approved manifest corrections and run it green. Remove the superseded root checks from Auth, Broadcasting, Http, Mail, Notifications, and Facade Documenter while keeping their package-specific assertions, and run each changed package test immediately.

Keep the repository-wide test concise by extracting its repeated manifest decoding and split-manifest discovery into two private helpers.

### Error and hook behavior

Update `GracefulDegradationTest.php` to assert the exact missing proxy error and real non-zero contract. Extend `IgnoredMethodsTest.php` with a non-static hook fixture and assert the native reflection failure instead of silent omission.

### Fixture lifecycle

After adding base-test cleanup, run the complete `tests/FacadeDocumenter` directory repeatedly and under the normal parallel suite. Tests must not depend on a fixture from a previous method and must leave no package-owned directories under the shared Testbench `BASE_PATH/app` clone.

### All-facade invariants

Move and extend `FacadeDocblocksTest.php` as described above. Before regeneration, run the parse-back method by itself and confirm all 49 current facades remain valid. After regeneration, run the whole file and confirm both syntax and lint invariants.

## Implementation and Verification Order

1. Move `FacadeDocblocksTest.php` into `tests/FacadeDocumenter`, add all-facade parse-back coverage while retaining the currently passing Support lint discovery, and run the file. This proves the 49-facade syntax baseline before regeneration.
2. Update `FacadeDocumenterTestCase` with deterministic fixture cleanup and unsuppressed directory creation; run the complete Facade Documenter suite to prove existing tests remain isolated.
3. Add `DynamicParameterTest` with every native parameter documented in order before the trailing PHPDoc-only parameter, run it red, complete `DynamicParameter`, and run it green.
4. Add `DocTagParsingTest`, run it red, correct `resolveDocTags()` and the generated `@see` boundary, and run it green.
5. Add the structured-method filtering regression, run it red, update the AST method pipeline in `facade.php`, and run it green together with existing static-prefix, ignored, and dedupe tests.
6. Add `TypePrecedenceTest`, the upstream generic regression, and PHPStan-template regression one file at a time; run each red. Update type rendering, nested depth forwarding, and template handling in `facade.php`, then run each affected test green.
7. Rename and extend `RelativeTypeResolutionTest`, then extend `ConstFetchResolutionTest`; run each red, add the shared relative and canonical class-name boundaries, and run both green.
8. Extend `ImportResolutionTest`, run red, replace import regex parsing with the bounded tokenizer and lexical resolver, correct consumer fallback order and the optional list, and run green with `TraitImportSourceTest` and class-docblock import coverage.
9. Extend `DefaultValueTest`, run red, replace default rendering with recursive AST nodes and explicit object failure, and run green.
10. Add `FilePublicationTest` and `PackageMetadataTest`, run them red, switch publication to Filesystem and add the direct split dependency, then run both green. Add the repository-wide Composer manifest tests, run them red, apply the approved root and split dependency, autoload, support, and package-config corrections, delete the completed Boost porting ledger, remove the duplicated package-local root checks, dump Composer autoload metadata, and run every changed test green.
11. Correct `GracefulDegradationTest` and `IgnoredMethodsTest`, running each immediately; remove stale source branches and comments they expose.
12. Add `: void` to remaining test methods one file at a time and run each changed test file immediately.
13. Run `./vendor/bin/phpunit --no-progress tests/FacadeDocumenter` and fix only verified failures at their owning boundary.
14. Change the moved all-facade test from Support-only lint discovery to production `autoload.psr-4` discovery. Run it to obtain the expected current drift without regenerating yet, then run its parse-back method separately to reconfirm current syntax.
15. Correct the `InteractsWithData` dual-Stringable contract and run its focused signature and coercion regressions.
16. Generate all 49 facades with the corrected CLI. Review every changed method line against its source, then run the all-facade test green.
17. Run `composer fix` once as the final checkpoint before code review. Review formatter changes, especially generated imports. Enum default shortening belongs to the generator's `shortenImportedGlobalTypes()` path and should already be stable before formatting; a formatter diff there is a defect to trace, not expected cleanup.
18. Perform a full self-review of every changed source caller/callee, generated facade diff, parser grammar branch, file mode path, split dependency, test cleanup path, and discovery candidate. Confirm no stale branch, comment, import, fixture, or generated method remains. Run targeted tests for any corrections; repeat `composer fix` only if they warrant another full-repository checkpoint.

No Testbench source is changed, so `composer test:testbench` is not required solely because these tests use the Testbench base class.

## Expected File Changes

### Source and metadata

- Modify `composer.json` through Composer for the approved root dependency declarations and constraint update. Composer also rewrites the local `composer.lock`, which `.gitignore:33` excludes from version control; it is not a tracked output of this work.
- Modify `src/boost/composer.json` and delete `src/boost/docs-ported.md`.
- Modify `src/console/composer.json`.
- Modify `src/database/composer.json`.
- Modify `src/engine/composer.json`.
- Modify `src/facade-documenter/facade.php`.
- Modify `src/facade-documenter/composer.json`.
- Modify `src/fortify/composer.json`.
- Modify `src/passkeys/composer.json`.
- Modify `src/prompts/composer.json`.
- Modify `src/support/src/Traits/InteractsWithData.php`.
- Modify `src/validation/composer.json`.
- Do not change `src/facade-documenter/README.md`; it is already thin, links canonical docs, and names the tracked upstream.

### Test infrastructure and coverage

- Modify `tests/FacadeDocumenter/FacadeDocumenterTestCase.php`.
- Create `tests/Composer/PackageManifestConsistencyTest.php`.
- Modify `tests/Auth/PackageMetadataTest.php`, `tests/Broadcasting/PackageMetadataTest.php`, `tests/Http/PackageMetadataTest.php`, `tests/Mail/PackageMetadataTest.php`, and `tests/Notifications/PackageMetadataTest.php` to remove their duplicated root cross-checks.
- Modify `tests/Support/Traits/InteractsWithDataTest.php` with the two Stringable contract regressions.
- Rename `tests/FacadeDocumenter/CaseInsensitiveDedupeTest.php` to `tests/FacadeDocumenter/ClassDocblockMethodFilteringTest.php`.
- Rename `tests/FacadeDocumenter/NullableSelfStaticTest.php` to `tests/FacadeDocumenter/RelativeTypeResolutionTest.php`.
- Move `tests/Support/FacadeDocblocksTest.php` to `tests/FacadeDocumenter/FacadeDocblocksTest.php`.
- Create `tests/FacadeDocumenter/DocTagParsingTest.php`.
- Create `tests/FacadeDocumenter/TypePrecedenceTest.php`.
- Create `tests/FacadeDocumenter/DynamicParameterTest.php`.
- Create `tests/FacadeDocumenter/FilePublicationTest.php`.
- Create `tests/FacadeDocumenter/PackageMetadataTest.php`.
- Modify the existing focused test files named in the Test Plan and complete missing test return types across the package.

### Generated facades

- Regenerate only facade files reported stale by the corrected generator.
- Review every generated diff; expected minimum files include `Date.php`, `Request.php`, `Inertia.php`, `Socialite.php`, and Sentry's `Facade.php`.
- Do not add a generated-facade workflow or hardcoded facade list.

## Deliberate Simplicity and Performance

- Keep one procedural CLI rather than introducing services, registries, or dependency injection into the upstream-shaped executable.
- Use `MethodTagValueNode` already held by the parser instead of parsing rendered text again.
- Use native PHP tokens for imports rather than a dependency or expanding regex.
- Do not cache imports or discovered facades. The command is cold, explicit, and currently completes a full facade pass in seconds; no performance defect exists.
- Use phpdoc-parser nodes already required by the package for default escaping and grammar.
- Use Hypervel's existing atomic filesystem primitive rather than local temp-file, lock, retry, or fsync code.
- Keep parse-back validation in tests, not the production generation path.
- Use one dynamic drift test in the normal suite, not a duplicated workflow command or second inventory.
- Do not reconstruct object expressions Reflection no longer retains. Fail clearly and point to the existing method-ignore hook.
- Do not add application, coroutine, worker-lifetime, or cache machinery. The generator process is isolated and short-lived.

## Completion Criteria

- Every focused regression fails before its owning source correction and passes afterward.
- Single-line and multiline docblock tags reach the same consumers, and generated `@see` references retain exactly one leading `\`.
- All generated `@method` tags use correct precedence and parse through phpdoc-parser; focused assertions prove semantics that syntax parsing cannot.
- Class-level method filters use AST identity, not rendered-string guesses.
- Standard class imports and qualified aliases resolve lexically without admitting closure or trait imports; namespaced classes beat global fallbacks, resolved names use declaration casing, and shortening preserves source alias casing.
- Native and PHPDoc relative types, constant references, and conditional targets use the correct declaring or selected class.
- Scalar, array, enum, finite-float, and non-finite-float defaults remain exact; unrepresentable object defaults stop with actionable context.
- Facade updates either complete atomically with their original mode or fail non-zero without changing the target.
- Every one of the current 49 first-party facades is discovered, syntax-checked, and linted through the normal test suite.
- Generated metadata matches current proxy source and contains no stale or dead entries.
- The split package declares every directly used runtime dependency.
- Every split `require` and `require-dev` dependency is represented consistently in the root manifest through one repository-wide invariant.
- Root and split autoload paths exist, and every split package carries the repository's canonical support metadata.
- Facade Documenter fixtures clean up deterministically and every package test method is typed.
- Focused tests, phpstan, cs-fixer, the full parallel suite, and `composer fix` are green.
- The final diff contains no superseded renderer mechanism, invalid fallback, workaround, duplicated validation path, stale comment, unused import, or unnecessary runtime overhead.

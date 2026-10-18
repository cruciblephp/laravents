<?php

declare(strict_types=1);

use Laravents\Discovery\ClassDiscovery;
use Laravents\Verify\Dependency;
use Laravents\Verify\DependencyScanner;
use Laravents\Verify\Verifier;

/**
 * The references the scanner finds in a piece of PHP, as `line Class` strings in order of first reference. Every
 * name is a class, interface, trait or enum except those listed: imported namespaces, and constants.
 *
 * @param  list<string>  $notClasses
 * @return list<string>
 */
function referencesIn(string $code, array $notClasses = [], string $prefix = ''): array
{
    $scanner = new DependencyScanner('M', static fn (string $name): bool => ! in_array($name, $notClasses, true));
    $found = $scanner->referencesIn("<?php\n".$code, $prefix);

    return array_map(static fn (string $class, int $line): string => $line.' '.$class, array_keys($found), array_values($found));
}

test('imports: single, aliased, several, grouped with sub-namespaces, leading backslash; functions and constants skipped', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace App;
        use M\One;
        use M\Two as Deux, M\Three;
        use M\Group\{Four, Sub\Five as Cinq, function helper, const LIMIT,};
        use \M\Six;
        use function M\fn1, M\fn2;
        use const M\CONST1;
        use function M\Fns\{a, b};
        PHP))->toBe([
        '3 M\One',
        '4 M\Two',
        '4 M\Three',
        '5 M\Group\Four',
        '5 M\Group\Sub\Five',
        '6 M\Six',
    ]);
});

test('names resolve as PHP resolves them: imports by first segment and case-insensitively, namespace\, fully qualified, the current namespace', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace App\Mod;
        use M\Api;
        use M\Thing as Alias;
        new Api\Events\Deep();
        new alias();
        new \M\Full();
        new namespace\Local();
        new Sibling\Nested();
        PHP, ['M\Api']))->toBe([
        '4 M\Thing',
        '5 M\Api\Events\Deep',
        '7 M\Full',
        '8 App\Mod\Local',
        '9 App\Mod\Sibling\Nested',
    ]);
});

test('each namespace in a file starts with no imports; the global namespace qualifies nothing', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace First;
        use M\Imported;
        new Imported();
        namespace Second;
        new Imported();
        PHP))->toBe(['3 M\Imported', '6 Second\Imported'])
        ->and(referencesIn("namespace First {\nuse M\\Braced;\nnew Braced();\n}\nnamespace {\nnew Braced();\n}"))->toBe(['3 M\Braced', '7 Braced'])
        ->and(referencesIn('new Plain(); new \Lead\Ing();'))->toBe(['2 Plain', '2 Lead\Ing']);
});

test('type declarations: parameters, promoted properties, properties, typed constants and return types, in every form', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace A;
        abstract class C
        {
            public const ?TypedConst ONE = null;
            const UNTYPED = SOME_CONST;
            public readonly Prop $prop;
            private static ?NullableProp $n = null;
            protected (DnfA&DnfB)|null $dnf;
            public int $a, $b;

            public function __construct(private Promoted $p, Param|Union $u, ?Nullable $q = null, Def $d = new DefNew(), int|Variadic ...$rest) {}

            public function list(InterA&InterB $x, #[Marker] string $s = Defaults::VALUE): RetType|static|null
            {
                $c = function (ClosureParam $a) use ($x, &$s): ClosureRet {
                    return null;
                };
                $f = static fn (ArrowParam $a): ArrowRet => null;

                return null;
            }

            abstract protected function &abs(): (RetA&RetB)|null;
        }
        PHP, ['A\SOME_CONST']))->toBe([
        '5 A\TypedConst',
        '7 A\Prop',
        '8 A\NullableProp',
        '9 A\DnfA',
        '9 A\DnfB',
        '12 A\Promoted',
        '12 A\Param',
        '12 A\Union',
        '12 A\Nullable',
        '12 A\Def',
        '12 A\DefNew',
        '12 A\Variadic',
        '14 A\InterA',
        '14 A\InterB',
        '14 A\Marker',
        '14 A\Defaults',
        '14 A\RetType',
        '16 A\ClosureParam',
        '16 A\ClosureRet',
        '19 A\ArrowParam',
        '19 A\ArrowRet',
        '24 A\RetA',
        '24 A\RetB',
    ]);
});

test('new, static access, ::class, instanceof, attributes, catch, extends, implements, traits and anonymous classes', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace A;
        #[First, Second(new InAttribute(), Argument::class)]
        final class C extends Base implements One, \M\Two
        {
            use TraitA, TraitB {
                TraitA::run insteadof TraitB;
                TraitB::run as protected walk; run as stroll;
            }

            public function run(object $x): void
            {
                Statically::call();
                $y = Constant::VALUE;
                if ($x instanceof Checked) {
                }
                try {
                } catch (CaughtA | \M\CaughtB $e) {
                } catch (CaughtC) {
                }
                $o = new class(1) extends AnonymousBase implements AnonymousInterface {
                    public AnonymousProperty $p;
                };
                match (true) { default => Matched::class };
            }
        }
        interface I extends ParentA, ParentB {}
        enum E: string implements EnumInterface { case Hearts = 'h'; const E Wild = E::Hearts; }
        PHP))->toBe([
        '3 A\First',
        '3 A\Second',
        '3 A\InAttribute',
        '3 A\Argument',
        '4 A\Base',
        '4 A\One',
        '4 M\Two',
        '6 A\TraitA',
        '6 A\TraitB',
        '13 A\Statically',
        '14 A\Constant',
        '15 A\Checked',
        '18 A\CaughtA',
        '18 M\CaughtB',
        '19 A\CaughtC',
        '21 A\AnonymousBase',
        '21 A\AnonymousInterface',
        '22 A\AnonymousProperty',
        '24 A\Matched',
        '27 A\ParentA',
        '27 A\ParentB',
        '28 A\EnumInterface',
        '28 A\E',
    ]);
});

test('comments, docblocks, strings, built-in types, self, static, parent, functions, constants, labels, named arguments and member names are not classes', function (): void {
    expect(referencesIn(<<<'PHP_WRAP'
    namespace A;
    /** @var M\Docblock $x Uses M\InDocblock */
    final class C extends P
    {
        // M\InComment
        # M\InHash
        /* M\InBlock */
        public function list(self $a, int $b, iterable $c, mixed $d, ?object $e, callable $f, array $g, bool|float|false|true $h): static|self|never|void
        {
            $s = 'M\InString' . "M\InDouble {$a->Foo} ${b}" . <<<EOT
            M\InHeredoc {$c}
            EOT;
            helper(); \M\fnq(); $k = \M\CONSTQ; $l = LOWER\CONSTANT; $m = PHP_EOL;
            self::X; static::y(); parent::z(); new static(); new self();
            foo(Named: 1);
            goto Label;
            Label:
            $a->Prop; $a?->Other(); $a::Member; Holder::new(); Holder::class;
            $n = new $s(); $s::find(); class_exists($s);
        }
    }
    PHP_WRAP, ['M\CONSTQ', 'A\LOWER\CONSTANT', 'A\PHP_EOL']))->toBe(['4 A\P', '19 A\Holder']);
});

test('a namespace import is an alias, not a reference; what is used through it is', function (): void {
    expect(referencesIn("namespace A;\nuse M\\Api;\nuse M\\Models;\nnew Api\\GiveNickname();", ['M\Api', 'M\Models']))
        ->toBe(['5 M\Api\GiveNickname'])
        ->and(referencesIn("namespace A;\nuse M\\{Models as Store};\nnew Store\\Order();", ['M\Models']))
        ->toBe(['4 M\Models\Order']);
});

test('a prefix keeps only the names under it, and a name is reported at its first reference', function (): void {
    expect(referencesIn(<<<'PHP'
        namespace M\Chat;
        use Illuminate\Support\Str;
        use M\Notification\Api\Sent;

        new Local();
        new Str();
        new Sent();
        new \Other\Thing();
        PHP, prefix: 'M\\'))->toBe(['4 M\Notification\Api\Sent', '6 M\Chat\Local']);
});

test('scan() gives each class its file\'s references into the modules namespace, not its own name', function (): void {
    $root = 'Laravents\Tests\Fixtures\Verify\Direction';
    $dependencies = (new DependencyScanner($root))->scan(ClassDiscovery::structures([dirname(__DIR__, 2).'/Fixtures/Verify/Direction']));
    $file = dirname(__DIR__, 2).'/Fixtures/Verify/Direction/Billing/Handlers/ChargeOnOrderPlaced.php';

    expect($dependencies)->toEqual([
        new Dependency($root.'\Billing\Handlers\ChargeOnOrderPlaced', $root.'\Orders\Api\OrderPlaced', $file, 8),
        new Dependency($root.'\Billing\Handlers\ChargeOnOrderPlaced', $root.'\Orders\Models\Order', $file, 15),
    ]);
});

test('the rules: direction, Api purity, the graph over every reference, and each elementary cycle once', function (): void {
    $dependency = static fn (string $class, string $uses): Dependency => new Dependency($class, $uses, 'file.php', 1);
    $verifier = new Verifier('M');
    $dependencies = [
        $dependency('M\A\Handlers\H', 'M\B\Api\Event'),
        $dependency('M\A\Handlers\H', 'M\B\Models\Model'),
        $dependency('M\A\Handlers\H', 'M\A\Models\Own'),
        $dependency('M\A\Api\Event', 'M\A\Models\Own'),
        $dependency('M\A\Api\Event', 'M\A\Api\Other'),
        $dependency('M\B\Handlers\H', 'M\A\Api\Event'),
        $dependency('M\B\Handlers\H', 'M\C\Api\Event'),
        $dependency('M\C\Handlers\H', 'M\A\Api\Event'),
        $dependency('M\D\Handlers\H', 'M\Root'),
    ];
    $modules = $verifier->modules(['M\A\Api\Event', 'M\B\Handlers\H', 'M\C\Handlers\H', 'M\D\Handlers\H', 'Outside\X']);
    $graph = $verifier->graph($modules, $dependencies);

    expect($modules)->toBe(['A', 'B', 'C', 'D'])
        ->and($verifier->direction($dependencies))->toBe([$dependencies[1]])
        ->and($verifier->apiPurity($dependencies))->toBe([$dependencies[3]])
        ->and($graph)->toBe(['A' => ['B'], 'B' => ['A', 'C'], 'C' => ['A'], 'D' => []])
        ->and($verifier->cycles($graph))->toBe([['A', 'B', 'A'], ['A', 'B', 'C', 'A']])
        ->and($verifier->cycles(['A' => ['B'], 'B' => ['C'], 'C' => []]))->toBe([]);

    // A cycle through an internal reference is a cycle too, and is reported under both rules.
    $internal = [$dependency('M\A\Handlers\H', 'M\B\Models\Model'), $dependency('M\B\Handlers\H', 'M\A\Api\Event')];

    expect($verifier->direction($internal))->toBe([$internal[0]])
        ->and($verifier->cycles($verifier->graph(['A', 'B'], $internal)))->toBe([['A', 'B', 'A']]);
});

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** A imagem roda `composer install --no-dev`: classe importada em `app/` não pode vir só de pacote de desenvolvimento. */
class ProductionDependenciesTest extends TestCase
{
    public function test_app_nao_importa_classe_de_pacote_so_de_desenvolvimento(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $prod = $this->prefixes($lock['packages']);
        // Os prefixos desta aplicação (`App\`, `Database\...`) ficam fora: o laravel/pint também os declara.
        $own = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $devOnly = array_diff_key($this->prefixes($lock['packages-dev']), $prod, $this->prefixes([$own]));

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        $missing = [];

        foreach ($files as $file) {
            foreach ($this->classesIn((string) file_get_contents($file->getPathname())) as $class) {
                foreach ($devOnly as $prefix => $package) {
                    if (str_starts_with($class.'\\', $prefix) && ! $this->coveredByProd($class, $prod)) {
                        $missing[$package] = $class;
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Pacote só em require-dev usado em app/: mova para require.');
    }

    public function test_le_nome_totalmente_qualificado_no_codigo(): void
    {
        $classes = $this->classesIn("<?php\nnamespace App;\nuse Foo\\Bar;\nclass X { function f() { return new \\Laravel\\Pint\\Thing; } }");

        $this->assertContains('Foo\Bar', $classes);
        $this->assertContains('Laravel\Pint\Thing', $classes);
        $this->assertNotContains('App', $classes);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return array<string, string> prefixo PSR-4 → pacote
     */
    private function prefixes(array $packages): array
    {
        $out = [];

        foreach ($packages as $package) {
            foreach (array_keys(($package['autoload']['psr-4'] ?? []) + ($package['autoload']['psr-0'] ?? [])) as $prefix) {
                $out[rtrim($prefix, '\\').'\\'] = $package['name'];
            }
        }

        return $out;
    }

    /**
     * @return list<string> nomes de `use` do topo e nomes totalmente qualificados (`\A\B`) no corpo
     */
    private function classesIn(string $code): array
    {
        preg_match_all('/^use (?:function |const )?([A-Za-z0-9_\\\\]+)/m', $code, $uses);
        preg_match_all('/\\\\([A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)+)/', $code, $qualified);

        return [...$uses[1], ...$qualified[1]];
    }

    /** @param  array<string, string>  $prod */
    private function coveredByProd(string $class, array $prod): bool
    {
        foreach (array_keys($prod) as $prefix) {
            if (str_starts_with($class.'\\', $prefix)) {
                return true;
            }
        }

        return false;
    }
}

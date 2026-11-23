<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** O zerar apaga o disco `local` e a pasta tmp dos dumps: nenhum teste pode apontar para o `storage/app` do desenvolvedor. */
class TestIsolationTest extends TestCase
{
    public function test_disco_local_e_pasta_tmp_dos_dumps_nao_sao_os_reais(): void
    {
        $this->assertStringNotContainsString(storage_path('app'), config()->string('backup.tmp_path'));
        $this->assertStringNotContainsString(storage_path('app'), Storage::disk('local')->path(''));
    }
}

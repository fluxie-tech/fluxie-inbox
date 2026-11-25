<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CspReportTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSIC = '{"csp-report":{"effective-directive":"script-src-elem","blocked-uri":"https://evil.test/x.js","document-uri":"https://app.test/inbox?ticket=9#a","disposition":"enforce"}}';

    private function report(string $body, string $type = 'application/csp-report', array $server = []): TestResponse
    {
        return $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type] + $server, $body);
    }

    private function classic(string $blocked, string $document = 'https://app.test/inbox', array $extra = []): string
    {
        return json_encode(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => $blocked, 'document-uri' => $document] + $extra], JSON_THROW_ON_ERROR);
    }

    public function test_relatorio_classico_vira_linha_na_auditoria_do_sistema(): void
    {
        $this->report(self::CLASSIC)->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('csp.violation', $log->action);
        $this->assertSame('script-src-elem', $log->subject);
        $this->assertNull($log->actor_user_id);
        $this->assertSame('Navegador', $log->actor_label);
        $this->assertSame('https://app.test/inbox', $log->detail['document']);
        $this->assertSame('https://evil.test/x.js', $log->detail['blocked']);
    }

    public function test_relatorio_novo_reports_json_tambem_e_aceito(): void
    {
        $body = json_encode([
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'https://evil.test/p.png', 'documentURL' => 'https://app.test/inbox?a=1', 'sourceFile' => 'https://app.test/app.js', 'lineNumber' => 7, 'disposition' => 'report']],
        ], JSON_THROW_ON_ERROR);

        $this->report($body, 'application/reports+json')->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('img-src', $log->subject);
        $this->assertSame('https://app.test/inbox', $log->detail['document']);
        $this->assertSame(7, $log->detail['line']);
    }

    public function test_violacao_repetida_na_mesma_hora_vira_uma_linha(): void
    {
        $this->report(self::CLASSIC)->assertNoContent();
        $this->report(self::CLASSIC)->assertNoContent();
        $this->assertSame(1, AuditLog::query()->count());

        Cache::forget('csp-report:'.sha1('script-src-elem|https://evil.test/x.js|https://app.test/inbox'));
        $this->report(self::CLASSIC)->assertNoContent();
        $this->assertSame(2, AuditLog::query()->count());
    }

    public function test_corpo_invalido_grande_ou_de_outro_tipo_responde_204_sem_gravar(): void
    {
        $this->report('{quebrado')->assertNoContent();
        $this->report(self::CLASSIC, 'text/plain')->assertNoContent();
        $this->report(str_pad(self::CLASSIC, 16385, ' '))->assertNoContent();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_nao_abre_sessao_nem_devolve_cookie(): void
    {
        $this->report(self::CLASSIC)->assertNoContent()->assertHeaderMissing('Set-Cookie');
    }

    public function test_responde_204_em_manutencao(): void
    {
        // Manutenção só na memória deste teste (store `array`): o arquivo `down` valeria para a suíte paralela inteira.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate([]);

        $this->report(self::CLASSIC)->assertNoContent();
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_responde_204_antes_do_primeiro_acesso(): void
    {
        $this->startFreshInstall();

        $this->report(self::CLASSIC)->assertNoContent();
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_acima_do_limite_por_ip_responde_429(): void
    {
        config(['security.csp_report_per_minute' => 2]);

        $this->report(self::CLASSIC)->assertNoContent();
        $this->report(self::CLASSIC)->assertNoContent();
        $this->report(self::CLASSIC)->assertStatus(429);
    }

    public function test_content_length_acima_do_limite_responde_204_sem_ler_o_corpo(): void
    {
        $this->report(self::CLASSIC, 'application/csp-report', ['CONTENT_LENGTH' => 16385])->assertNoContent();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_teto_global_por_hora_descarta_o_excedente_e_avisa_uma_vez(): void
    {
        config(['security.csp_report_per_hour' => 2]);
        Log::spy();

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->report($this->classic("https://evil.test/{$name}.png"))->assertNoContent();
        }

        $this->assertSame(2, AuditLog::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'CSP: teto de relatórios por hora atingido')->once();
    }

    public function test_blocked_e_source_tambem_perdem_query_e_fragmento(): void
    {
        $this->report($this->classic('https://evil.test/p.png?token=abc#x', extra: ['source-file' => 'https://app.test/app.js?v=1#l']))->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('https://evil.test/p.png', $log->detail['blocked']);
        $this->assertSame('https://app.test/app.js', $log->detail['source']);
    }

    public function test_reports_json_com_mais_de_10_itens_grava_so_10(): void
    {
        $items = array_map(fn (int $i) => ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => "https://evil.test/{$i}.png", 'documentURL' => 'https://app.test/inbox']], range(1, 12));

        $this->report(json_encode($items, JSON_THROW_ON_ERROR), 'application/reports+json')->assertNoContent();

        $this->assertSame(10, AuditLog::query()->count());
    }

    public function test_texto_de_mais_de_255_caracteres_sai_cortado(): void
    {
        $this->report($this->classic('https://evil.test/'.str_repeat('x', 400)))->assertNoContent();

        $this->assertSame(255, mb_strlen(AuditLog::query()->sole()->detail['blocked']));
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CspReportTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSIC = '{"csp-report":{"effective-directive":"script-src-elem","blocked-uri":"https://evil.test/x.js","document-uri":"https://app.test/inbox?ticket=9#a","disposition":"enforce"}}';

    private function report(string $body, string $type = 'application/csp-report'): TestResponse
    {
        return $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type], $body);
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
}

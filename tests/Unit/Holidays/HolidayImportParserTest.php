<?php

namespace Tests\Unit\Holidays;

use App\Enums\HolidayKind;
use App\Services\Holidays\HolidayImportParser;
use App\Services\Holidays\HolidayImportRow;
use PHPUnit\Framework\TestCase;

class HolidayImportParserTest extends TestCase
{
    /**
     * @param  list<string>  $existing
     * @return array{error: ?string, rows: list<HolidayImportRow>}
     */
    private function parse(string $contents, array $existing = []): array
    {
        return (new HolidayImportParser)->parse($contents, $existing);
    }

    public function test_le_a_lista_com_as_duas_formas_de_data_e_os_tipos(): void
    {
        $json = json_encode([
            ['data' => '2027-01-01', 'nome' => 'Confraternização Universal', 'tipo' => 'nacional'],
            ['data' => '25/01/2027', 'nome' => 'Aniversário de São Paulo', 'tipo' => 'municipal'],
            ['data' => '2027-07-09', 'nome' => 'Revolução Constitucionalista', 'tipo' => 'estadual'],
            ['data' => '2027-02-10', 'nome' => 'Quarta de Cinzas', 'tipo' => 'facultativo'],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parse($json);

        $this->assertNull($result['error']);
        $this->assertCount(4, $result['rows']);
        $this->assertSame('2027-01-01', $result['rows'][0]->date);
        $this->assertSame('2027-01-25', $result['rows'][1]->date);
        $this->assertSame(
            [HolidayKind::NACIONAL, HolidayKind::MUNICIPAL, HolidayKind::ESTADUAL, HolidayKind::FACULTATIVO],
            array_map(fn ($r) => $r->kind, $result['rows']),
        );
        $this->assertSame('Aniversário de São Paulo', $result['rows'][1]->name);
        $this->assertNull($result['rows'][0]->error);
        $this->assertFalse($result['rows'][0]->exists);
    }

    public function test_acha_o_json_dentro_do_bloco_markdown_com_texto_em_volta(): void
    {
        $item = '[{"data":"2027-01-01","nome":"Ano Novo","tipo":"nacional"}]';
        $com = "Aqui está:\n```json\n{$item}\n```\nEspero que ajude!";
        $sem = "Segue {$item} pronto.";

        foreach ([$com, $sem] as $text) {
            $result = $this->parse($text);
            $this->assertNull($result['error']);
            $this->assertSame('Ano Novo', $result['rows'][0]->name);
        }
    }

    public function test_bloco_markdown_com_qualquer_etiqueta_de_linguagem_e_lido(): void
    {
        $item = '[{"data":"2027-01-01","nome":"Ano Novo","tipo":"nacional"}]';

        foreach (['JSON', 'javascript', 'Json'] as $tag) {
            $result = $this->parse("Aqui:\n```{$tag}\n{$item}\n```\nFim.");
            $this->assertNull($result['error'], $tag);
            $this->assertSame('Ano Novo', $result['rows'][0]->name);
        }
    }

    public function test_arquivo_que_nao_e_lista_vazio_ou_grande_demais_da_um_erro_so(): void
    {
        $grande = json_encode(array_fill(0, 501, ['data' => '2027-01-01', 'nome' => 'X', 'tipo' => 'nacional']), JSON_THROW_ON_ERROR);

        foreach (['nao json', '{"a":1}', '[]', $grande] as $contents) {
            $result = $this->parse($contents);
            $this->assertSame($contents === $grande ? 'O arquivo passa de 500 feriados.' : 'O arquivo não é uma lista JSON de feriados.', $result['error']);
            $this->assertSame([], $result['rows']);
        }
    }

    public function test_json_invalido_ou_utf8_quebrado_da_o_erro_do_arquivo(): void
    {
        foreach (['[{"data":"2027-01-01","nome":"'.chr(0xB1).'","tipo":"nacional"}]', '[{"data":'] as $contents) {
            $this->assertSame('O arquivo não é uma lista JSON de feriados.', $this->parse($contents)['error']);
        }
    }

    public function test_item_que_nao_e_objeto_vira_erro_da_linha(): void
    {
        $rows = $this->parse('["x", 1, null, []]')['rows'];

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('Data inválida.', $row->error);
        }
    }

    public function test_linha_com_erro_nao_reserva_a_data_para_a_repeticao(): void
    {
        $json = json_encode([
            ['data' => '2027-01-01', 'nome' => '', 'tipo' => 'nacional'],
            ['data' => '2027-01-01', 'nome' => 'Boa', 'tipo' => 'nacional'],
            ['data' => '2027-01-01', 'nome' => 'Outra', 'tipo' => 'nacional'],
        ], JSON_THROW_ON_ERROR);

        $rows = $this->parse($json)['rows'];

        $this->assertSame('Informe o nome do feriado.', $rows[0]->error);
        $this->assertNull($rows[1]->error);
        $this->assertSame('Data repetida no arquivo.', $rows[2]->error);
    }

    public function test_data_inexistente_nome_vazio_ou_longo_e_tipo_desconhecido_viram_erro_da_linha(): void
    {
        $json = json_encode([
            ['data' => '2027-02-30', 'nome' => 'A', 'tipo' => 'nacional'],
            ['data' => '31/04/2027', 'nome' => 'B', 'tipo' => 'nacional'],
            ['data' => '2027-03-01', 'nome' => '  ', 'tipo' => 'nacional'],
            ['data' => '2027-03-02', 'nome' => str_repeat('a', 121), 'tipo' => 'nacional'],
            ['data' => '2027-03-03', 'nome' => "a\x01b", 'tipo' => 'nacional'],
            ['data' => '2027-03-04', 'nome' => 'C', 'tipo' => 'manual'],
        ], JSON_THROW_ON_ERROR);

        $rows = $this->parse($json)['rows'];

        $this->assertSame('Data inválida.', $rows[0]->error);
        $this->assertNull($rows[0]->date);
        $this->assertSame('Data inválida.', $rows[1]->error);
        $this->assertSame('Informe o nome do feriado.', $rows[2]->error);
        $this->assertSame('O nome passa de 120 caracteres.', $rows[3]->error);
        $this->assertSame('O nome tem caracteres que não podem ser usados.', $rows[4]->error);
        $this->assertSame('Tipo desconhecido (use nacional, estadual, municipal ou facultativo).', $rows[5]->error);
        $this->assertNull($rows[5]->kind);
    }

    public function test_data_repetida_no_arquivo_vale_a_primeira(): void
    {
        $json = json_encode([
            ['data' => '2027-01-01', 'nome' => 'Primeiro', 'tipo' => 'nacional'],
            ['data' => '01/01/2027', 'nome' => 'Segundo', 'tipo' => 'nacional'],
        ], JSON_THROW_ON_ERROR);

        $rows = $this->parse($json)['rows'];

        $this->assertNull($rows[0]->error);
        $this->assertSame('Data repetida no arquivo.', $rows[1]->error);
    }

    public function test_data_ja_cadastrada_fica_marcada_como_existente(): void
    {
        $json = json_encode([
            ['data' => '2027-01-01', 'nome' => 'A', 'tipo' => 'nacional'],
            ['data' => '2027-01-02', 'nome' => 'B', 'tipo' => 'nacional'],
        ], JSON_THROW_ON_ERROR);

        $rows = $this->parse($json, ['2027-01-01'])['rows'];

        $this->assertTrue($rows[0]->exists);
        $this->assertNull($rows[0]->error);
        $this->assertFalse($rows[1]->exists);
    }

    public function test_aceita_as_chaves_em_ingles(): void
    {
        $rows = $this->parse('[{"date":"2027-01-01","name":"New Year","type":"nacional"}]')['rows'];

        $this->assertNull($rows[0]->error);
        $this->assertSame('2027-01-01', $rows[0]->date);
        $this->assertSame('New Year', $rows[0]->name);
        $this->assertSame(HolidayKind::NACIONAL, $rows[0]->kind);
    }
}

<?php

namespace R2soft\NfseNacional\Tests\Danfse;

use PHPUnit\Framework\TestCase;
use R2soft\NfseNacional\Danfse\DanfseGerador;

/**
 * Cobre os itens de "DanfseGeradorTest" em docs/danfse.md.
 *
 * Os textos longos usam só caracteres ASCII para o substr não partir um
 * caractere multibyte no meio e invalidar o XML.
 */
class DanfseGeradorTest extends TestCase
{
    private static function xml(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/danfse/' . $arquivo);
    }

    /** Páginas do PDF: ocorrências de "/Type /Page" que não sejam "/Type /Pages". */
    private static function paginas(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function testGerarPdfDevolvePdfComExatamenteUmaPagina(): void
    {
        foreach (['nfse-pj-suporte.xml', 'nfse-pf-agenda.xml', 'nfse-assinatura.xml'] as $arquivo) {
            $pdf = (new DanfseGerador())->gerarPdf(self::xml($arquivo));
            $this->assertStringStartsWith('%PDF', $pdf, $arquivo . ': não começa com %PDF');
            $this->assertSame(1, self::paginas($pdf), $arquivo . ': deveria ter exatamente 1 página');
        }
    }

    public function testGerarPdfComTextosLongosCabeEmUmaPagina(): void
    {
        $xml = self::xml('nfse-pj-suporte.xml');
        $descricao = substr(str_repeat('Descricao do servico de teste com texto longo para quebrar linha. ', 40), 0, 1300);
        $info = substr(str_repeat('Informacoes complementares de teste com texto longo para quebrar linha. ', 40), 0, 2000);
        $xml = str_replace('</cServ>', '<xDescServ>' . $descricao . '</xDescServ></cServ>', $xml);
        $xml = str_replace('</cServ>', '</cServ><infoCompl><xInfComp>' . $info . '</xInfComp></infoCompl>', $xml);

        $pdf = (new DanfseGerador())->gerarPdf($xml);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, self::paginas($pdf), 'os textos longos deveriam caber em 1 página');
    }

    public function testGerarHtmlContemQrEUrlDeConsulta(): void
    {
        $xml = self::xml('nfse-pj-suporte.xml');
        preg_match('/Id="NFS(\d+)"/', $xml, $m);
        $chave = $m[1];

        $html = (new DanfseGerador())->gerarHtml($xml);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString(
            'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&amp;chave=' . $chave,
            $html
        );
    }

    public function testMarcaDaguaCanceladaESubstituida(): void
    {
        $xml = self::xml('nfse-pj-suporte.xml');
        $this->assertStringContainsString('CANCELADA', (new DanfseGerador())->gerarHtml($xml, ['cancelada' => true]));
        $this->assertStringContainsString('SUBSTITUÍDA', (new DanfseGerador())->gerarHtml($xml, ['substituida' => true]));
    }

    public function testHomologacaoMostraAviso(): void
    {
        $xml = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', self::xml('nfse-pj-suporte.xml'));
        $this->assertStringContainsString('NFS-e SEM VALIDADE JURÍDICA', (new DanfseGerador())->gerarHtml($xml));
    }
}

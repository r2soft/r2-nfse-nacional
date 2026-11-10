<?php

namespace R2soft\NfseNacional\Danfse;

/**
 * Descrições das tabelas de códigos do Anexo I do leiaute DPS/NFS-e v1.01
 * (NT SE/CGNFS-e nº 008/2026) e busca de municípios por código IBGE (Anexo A).
 *
 * Código fora da tabela é devolvido como está (ver "Tabelas" em docs/danfse.md).
 */
class Tabelas
{
    /** @var array<string, array<string, string>> */
    private const TABELAS = [
        'ambGer' => [
            '1' => 'Sistema Próprio do Município',
            '2' => 'Sefin Nacional NFS-e',
        ],
        'tpAmb' => [
            '1' => 'Produção',
            '2' => 'Homologação',
        ],
        'tpEmit' => [
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
        ],
        'cStat' => [
            '100' => 'NFS-e Gerada',
            '102' => 'NFS-e de Decisão Judicial ou Administrativa',
            '103' => 'NFS-e Avulsa',
            '107' => 'NFS-e MEI',
        ],
        'finNFSe' => [
            '0' => 'NFS-e regular',
        ],
        'opSimpNac' => [
            '1' => 'Não Optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
        ],
        'regApTribSN' => [
            '1' => 'Regime de apuração dos tributos federais e municipal pelo SN',
            '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo',
            '3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo',
        ],
        'regEspTrib' => [
            '0' => 'Nenhum',
            '1' => 'Ato Cooperado (Cooperativa)',
            '2' => 'Estimativa',
            '3' => 'Microempresa Municipal',
            '4' => 'Notário ou Registrador',
            '5' => 'Profissional Autônomo',
            '6' => 'Sociedade de Profissionais',
            '9' => 'Outros',
        ],
        'tribISSQN' => [
            '1' => 'Operação Tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de Serviço',
            '4' => 'Não Incidência',
        ],
        'tpImunidade' => [
            '0' => 'Imunidade (tipo não informado na nota de origem)',
            '1' => 'Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)',
            '2' => 'Entidades religiosas e templos de qualquer culto, inclusive suas organizações assistenciais e beneficentes (CF88, Art 150, VI, b)',
            '3' => 'Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos, atendidos os requisitos da lei (CF88, Art 150, VI, c)',
            '4' => 'Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)',
            '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil contendo obras musicais ou literomusicais de autores brasileiros e/ou obras em geral interpretadas por artistas brasileiros bem como os suportes materiais ou arquivos digitais que os contenham (CF88, Art 150, VI, e)',
        ],
        'tpSusp' => [
            '1' => 'Exigibilidade Suspensa por Decisão Judicial',
            '2' => 'Exigibilidade Suspensa por Processo Administrativo',
        ],
        'tpRetISSQN' => [
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
        ],
        'tpRetPisCofins' => [
            '0' => 'PIS/COFINS/CSLL Não Retidos',
            '1' => 'PIS/COFINS Retido',
            '2' => 'PIS/COFINS Não Retido',
            '3' => 'PIS/COFINS/CSLL Retidos',
            '4' => 'PIS/COFINS Retidos, CSLL Não Retido',
            '5' => 'PIS Retido, COFINS/CSLL Não Retido',
            '6' => 'COFINS Retido, PIS/CSLL Não Retido',
            '7' => 'PIS Não Retido, COFINS/CSLL Retidos',
            '8' => 'PIS/COFINS Não Retidos, CSLL Retido',
            '9' => 'COFINS Não Retido, PIS/CSLL Retidos',
        ],
    ];

    /** @var array<string, array{0: string, 1: string}>|null */
    private static $municipios;

    /**
     * Descrição de um código em uma tabela do Anexo I.
     * Código fora da tabela é devolvido como está; código ausente, "-".
     */
    public static function descricao(string $tabela, ?string $codigo): string
    {
        if ($codigo === null || $codigo === '') {
            return '-';
        }
        $t = self::TABELAS[$tabela] ?? [];

        return $t[$codigo] ?? $codigo;
    }

    /**
     * Município pelo código IBGE de 7 dígitos.
     *
     * @return array{0: string nome, 1: string uf}|null null quando o código não existe
     */
    public static function municipio(string $cMun): ?array
    {
        if ($cMun === '') {
            return null;
        }
        if (self::$municipios === null) {
            $arquivo = dirname(__DIR__, 2) . '/resources/danfse/municipios.json';
            self::$municipios = json_decode((string) file_get_contents($arquivo), true) ?: [];
        }

        return self::$municipios[$cMun] ?? null;
    }
}

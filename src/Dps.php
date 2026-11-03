<?php

namespace R2soft\NfseNacional;

/**
 * DPS 1.01 (sem assinatura) a partir do $dadosRps do consumer.
 * Conteúdo e ordem dos campos seguem a NFS-e 3850 de Jaraguá/GO, aceita pela Sefin em 2026.
 * Só Simples Nacional ME/EPP com ISS não retido: o resto não foi validado e é recusado.
 */
final class Dps
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    public const VERSAO = '1.01';
    public const VER_APLIC = 'R2-NFSE-NAC-1.0';
    public const SERIE_PADRAO = '2';

    /** "DPS" + cLocEmi(7) + tipo de inscrição (1 CPF, 2 CNPJ) + inscrição(14) + série(5) + nDPS(15). */
    public static function id(string $codigoMunicipio, string $tipo, string $cpfCnpj, string $serie, string $numero): string
    {
        return 'DPS' . self::digitos($codigoMunicipio)
            . (strtoupper($tipo) === 'CPF' ? '1' : '2')
            . str_pad(self::documento($cpfCnpj), 14, '0', STR_PAD_LEFT)
            . str_pad(self::digitos($serie), 5, '0', STR_PAD_LEFT)
            . str_pad(ltrim(self::digitos($numero), '0'), 15, '0', STR_PAD_LEFT);
    }

    public static function xml(array $d, string $ambiente, \DateTimeImmutable $agora): string
    {
        if ((int) ($d['optante_simples_nacional'] ?? 0) !== 1) {
            throw new NfseNacionalException('Padrão nacional só suportado para Simples Nacional ME/EPP (optante pelo Simples = 1).');
        }
        $serie     = self::digitos((string) ($d['serie'] ?? '')) ?: self::SERIE_PADRAO;
        $numero    = ltrim(self::digitos((string) ($d['numero_rps'] ?? '')), '0');
        $municipio = self::digitos((string) ($d['codigo_municipio'] ?? ''));
        $tipoPrest = strtoupper((string) ($d['prestador_tipo'] ?? 'CNPJ')) === 'CPF' ? 'CPF' : 'CNPJ';
        $docPrest  = self::documento((string) ($d['prestador_cpf_cnpj'] ?? ''));
        $cTribNac  = self::digitos((string) ($d['item_lista_servico'] ?? ''));
        $valor     = round((float) ($d['valor_servicos'] ?? 0), 2);

        if ($numero === '' || strlen($municipio) !== 7 || $docPrest === '') {
            throw new NfseNacionalException('DPS sem número do RPS, código IBGE do município (7 dígitos) ou documento do prestador.');
        }
        if (strlen($cTribNac) !== 6) {
            throw new NfseNacionalException("Item da lista de serviço \"{$d['item_lista_servico']}\" não vira código de tributação nacional de 6 dígitos (ex.: 01.07.01).");
        }
        if ($valor <= 0) {
            throw new NfseNacionalException('Valor do serviço precisa ser maior que zero.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $dps = $doc->appendChild($doc->createElementNS(self::NS, 'DPS'));
        $dps->setAttribute('versao', self::VERSAO);
        $inf = self::el($dps, 'infDPS');
        $inf->setAttribute('Id', self::id($municipio, $tipoPrest, $docPrest, $serie, $numero));

        self::el($inf, 'tpAmb', $ambiente === 'prod' ? '1' : '2');
        self::el($inf, 'dhEmi', $agora->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->modify('-60 seconds')->format('Y-m-d\TH:i:sP'));
        self::el($inf, 'verAplic', self::VER_APLIC);
        self::el($inf, 'serie', $serie);
        self::el($inf, 'nDPS', $numero);
        self::el($inf, 'dCompet', substr((string) ($d['competencia'] ?? ''), 0, 10));
        self::el($inf, 'tpEmit', '1');
        self::el($inf, 'cLocEmi', $municipio);

        $prest = self::el($inf, 'prest');
        self::el($prest, $tipoPrest, $docPrest);
        if (trim((string) ($d['prestador_im'] ?? '')) !== '') {
            self::el($prest, 'IM', trim((string) $d['prestador_im']));
        }
        $reg = self::el($prest, 'regTrib');
        self::el($reg, 'opSimpNac', '3');
        self::el($reg, 'regApTribSN', '1');
        self::el($reg, 'regEspTrib', '0');

        $docToma = self::documento((string) ($d['tomador_cpf_cnpj'] ?? ''));
        if ($docToma !== '') {
            $toma = self::el($inf, 'toma');
            self::el($toma, strlen($docToma) === 11 ? 'CPF' : 'CNPJ', $docToma);
            self::el($toma, 'xNome', self::texto($d['tomador_razao_social'] ?? '', 300) ?: 'NAO INFORMADO');
            $cMun = self::digitos((string) ($d['tomador_cod_municipio'] ?? ''));
            $cep  = self::digitos((string) ($d['tomador_cep'] ?? ''));
            if (strlen($cMun) === 7 && strlen($cep) === 8) {
                $end    = self::el($toma, 'end');
                $endNac = self::el($end, 'endNac');
                self::el($endNac, 'cMun', $cMun);
                self::el($endNac, 'CEP', $cep);
                self::el($end, 'xLgr', self::texto($d['tomador_endereco'] ?? '', 255) ?: 'NAO INFORMADO');
                self::el($end, 'nro', self::texto($d['tomador_numero'] ?? '', 60) ?: 'S/N');
                $cpl = self::texto($d['tomador_complemento'] ?? '', 156);
                if ($cpl !== '') {
                    self::el($end, 'xCpl', $cpl);
                }
                self::el($end, 'xBairro', self::texto($d['tomador_bairro'] ?? '', 60) ?: 'NAO INFORMADO');
            }
        }

        $serv = self::el($inf, 'serv');
        self::el(self::el($serv, 'locPrest'), 'cLocPrestacao', $municipio);
        $cServ = self::el($serv, 'cServ');
        self::el($cServ, 'cTribNac', $cTribNac);
        self::el($cServ, 'xDescServ', self::texto($d['discriminacao'] ?? '', 2000) ?: 'Prestação de serviços');
        $nbs = self::digitos((string) ($d['numero_nbs'] ?? ''));
        if ($nbs !== '') {
            self::el($cServ, 'cNBS', $nbs);
        }

        $valores = self::el($inf, 'valores');
        self::el(self::el($valores, 'vServPrest'), 'vServ', number_format($valor, 2, '.', ''));
        $trib = self::el($valores, 'trib');
        $mun  = self::el($trib, 'tribMun');
        self::el($mun, 'tribISSQN', '1');
        self::el($mun, 'tpRetISSQN', '1');
        $pc = self::el(self::el($trib, 'tribFed'), 'piscofins');
        self::el($pc, 'CST', '00');
        self::el($pc, 'tpRetPisCofins', '0');
        $tot = self::el(self::el($trib, 'totTrib'), 'vTotTrib');
        self::el($tot, 'vTotTribFed', '0.00');
        self::el($tot, 'vTotTribEst', '0.00');
        self::el($tot, 'vTotTribMun', '0.00');

        return $doc->saveXML($doc->documentElement);
    }

    private static function el(\DOMNode $pai, string $nome, ?string $valor = null): \DOMElement
    {
        $doc = $pai instanceof \DOMDocument ? $pai : $pai->ownerDocument;
        $no  = $doc->createElementNS(self::NS, $nome);
        if ($valor !== null) {
            $no->appendChild($doc->createTextNode($valor));
        }
        $pai->appendChild($no);
        return $no;
    }

    /** UTF-8, sem caracteres de controle, espaços colapsados, cortado em $max. */
    private static function texto($valor, int $max): string
    {
        $s = (string) $valor;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, $max);
    }

    private static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor);
    }

    /** CPF/CNPJ sem máscara; mantém letras do CNPJ alfanumérico. */
    private static function documento(string $valor): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($valor));
    }
}

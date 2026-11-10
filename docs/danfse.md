# DANFSe v2.0 gerado a partir do XML da NFS-e

Fonte: **Nota Técnica SE/CGNFS-e nº 008/2026, versão 1.02 (14/07/2026)**:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf

A API de DANFSe do ADN foi suspensa em 03/08/2026. Desde então, cada emissor gera o próprio DANFSe.
Códigos e descrições vêm do Anexo I (leiaute DPS/NFS-e v1.01, de 09/02/2026). Os nomes de
municípios vêm do Anexo A.

## API pública

```php
use R2soft\NfseNacional\Danfse\DanfseGerador;

$pdf  = (new DanfseGerador())->gerarPdf($xmlNfse);                    // bytes do PDF ("%PDF...")
$pdf  = (new DanfseGerador())->gerarPdf($xmlNfse, ['cancelada' => true]);   // marca d'água CANCELADA
$pdf  = (new DanfseGerador())->gerarPdf($xmlNfse, ['substituida' => true]); // marca d'água SUBSTITUÍDA
$html = (new DanfseGerador())->gerarHtml($xmlNfse, $opcoes);           // mesmo HTML que vai para o PDF
```

- `$xmlNfse` é o XML da NFS-e: raiz `<NFSe>` no namespace `http://www.sped.fazenda.gov.br/nfse`, com
  `infNFSe` e a `DPS` dentro dele. É o mesmo que a Sefin devolve e que fica em `nfses.xml_retorno`.
- XML inválido ou sem `infNFSe` lança `R2soft\NfseNacional\NfseNacionalException`.
- `cancelada` e `substituida` vêm de quem chama: o XML da NFS-e não traz o evento.

## Estrutura

| Arquivo | Papel |
|---|---|
| `src/Danfse/Tabelas.php` | Descrições dos códigos (seção "Tabelas") e `municipio(string $cMun): ?array{0: string nome, 1: string uf}`, que lê `resources/danfse/municipios.json` uma vez e guarda em cache estático. |
| `src/Danfse/DanfseDados.php` | `public static function doXml(string $xml, array $opcoes = []): array`. Lê o XML com DOMXPath (prefixo `n` = namespace da NFS-e) e devolve os textos **já formatados**, prontos para impressão (seção "Campos"). Não gera HTML. |
| `src/Danfse/DanfseHtml.php` | `public static function render(array $dados): string`. HTML + CSS para o dompdf (seção "Leiaute"). Escapa todo o texto com `htmlspecialchars`. |
| `src/Danfse/DanfseGerador.php` | Junta as duas, gera o QR Code e chama o dompdf. |
| `resources/danfse/logo-nfse.png` | Logotipo oficial da NFS-e (extraído da própria NT). |
| `resources/danfse/municipios.json` | `{"5211800": ["Jaraguá", "GO"], ...}` com os 5570 municípios. |

Dependências novas no `composer.json`: `"dompdf/dompdf": "^3.1"`, `"chillerlan/php-qrcode": "^5.0"`, `"ext-gd": "*"`.

## Regras gerais (NT item 2.1 e notas)

- Só se imprime o que está no XML. Campo sem informação no XML vira **"-"** (nota 12).
- Truncamento: quando o texto passa do limite, corta e termina com "..." (limite = tamanho do campo - 3 caracteres, mais "...").
- Datas: `DD/MM/AAAA`. Data e hora: `DD/MM/AAAA hh:mm:ss`, usando a hora **como está no XML**, sem
  converter fuso. `2026-11-09T17:16:31-03:00` → `09/11/2026 17:16:31`.
- Dinheiro: `R$ 1.234,56`. Percentual: `2,00%`. Os valores vêm com ponto decimal no XML.
- CNPJ `nn.nnn.nnn/nnnn-nn`, CPF `nnn.nnn.nnn-nn`, NIF como está, CEP `nn.nnn-nnn`.
- Código de tributação nacional `nn.nn.nn` (6 dígitos: `010701` → `01.07.01`). NBS `n.nnnn.nn.nn`
  (9 dígitos: `115013000` → `1.1501.30.00`).

## Campos

O caminho é relativo a `NFSe/infNFSe/`, e `DPS/` quer dizer `DPS/infDPS/`. A coluna "Lim." é o
tamanho do campo da NT.

### Cabeçalho
| Campo | Origem | Formato |
|---|---|---|
| Município | `xLocEmi` + UF de `emit/enderNac/UF` | `Município: Jaraguá / GO` (Lim. 37). Não exibir quando o item do código de tributação nacional (os 2 primeiros dígitos de `DPS/serv/cServ/cTribNac`) for `99`. |
| Ambiente Gerador | `ambGer` | descrição (tabela ambGer) |
| Tipo de Ambiente | `DPS/tpAmb` | descrição (tabela tpAmb) |
| Aviso | `DPS/tpAmb` = 2 | `NFS-e SEM VALIDADE JURÍDICA` |

### Dados da NFS-e (labels em CAIXA ALTA 7pt)
| Campo | Origem | Formato |
|---|---|---|
| Chave de Acesso da NFS-e | atributo `Id` de `infNFSe` sem o prefixo `NFS` | 50 dígitos, num bloco só |
| Número da NFS-e | `nNFSe` | |
| Competência da NFS-e | `DPS/dCompet` | DD/MM/AAAA |
| Data e Hora da emissão da NFS-e | `dhProc` | DD/MM/AAAA hh:mm:ss |
| Número da DPS | `DPS/nDPS` | |
| Série da DPS | `DPS/serie` | |
| Data e Hora da emissão da DPS | `DPS/dhEmi` | DD/MM/AAAA hh:mm:ss |
| Emitente da NFS-e | `DPS/tpEmit` | descrição (tabela tpEmit) |
| Situação da NFS-e | `cStat` | descrição (tabela cStat), Lim. 40 |
| Finalidade | `DPS/IBSCBS/finNFSe` | descrição (tabela finNFSe), Lim. 40. Sem o campo, "-". |
| QR Code | `https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=` + chave | |

### Prestador / Fornecedor
Lê primeiro `DPS/prest/`. Se o campo não estiver lá, usa `emit/`. Quando o prestador é o emitente, a
DPS só traz o CNPJ, e o nome e o endereço ficam em `emit/enderNac`.
| Campo | Origem | Formato |
|---|---|---|
| CNPJ / CPF / NIF | `CNPJ` ou `CPF` ou `NIF` | máscara |
| Indicador Municipal (Inscrição) | `DPS/prest/IM` | Lim. 15 |
| Telefone | `fone` | Lim. 20 |
| Nome / Nome Empresarial | `xNome` | Lim. 80 |
| Município / Sigla UF | `end/endNac/cMun` (tabela de municípios) ou `enderNac/cMun`+`UF`; exterior: `end/endExt/xCidade` | `Jaraguá / GO`, Lim. 37 |
| Código IBGE / CEP | `cMun` + `CEP`; exterior: `cEndPost` | `5211800 / 76.330-000`, Lim. 21 |
| Endereço | `xLgr`, `nro`, `xCpl`, `xBairro` (os vazios ficam de fora) | `RUA R - 1, 2314, REGINA PARK`, Lim. 80 |
| E-mail | `email` | Lim. 80 |
| Simples Nacional na Data de Competência | `DPS/prest/regTrib/opSimpNac` | descrição, Lim. 40 |
| Regime de Apuração Tributária pelo SN | `DPS/prest/regTrib/regApTribSN` | descrição, Lim. 80 |

Em `DPS/prest/end`, o `cMun` e o `CEP` ficam dentro de `endNac`, enquanto `xLgr`, `nro`, `xCpl` e
`xBairro` ficam direto em `end`. Em `emit/enderNac`, todos ficam dentro de `enderNac`.

### Tomador / Adquirente
Mesmos campos e formatos do prestador (sem o Simples Nacional), lidos de `DPS/toma/`. A estrutura
do endereço é a de `DPS/prest/end`. Sem `DPS/toma`, o bloco inteiro vira uma linha só:
`TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e`.

### Destinatário da Operação
- Com `DPS/IBSCBS/dest`: mesmos campos do tomador, sem a Inscrição Municipal.
- Com `DPS/IBSCBS/indDest` = 0: uma linha só, `O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO`.
- Sem `DPS/IBSCBS` ou sem os dois: uma linha só, `DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e`.

### Intermediário da Operação
- Com `DPS/interm`: mesmos campos do tomador.
- Sem `DPS/interm`: uma linha só, `INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e`.

### Serviço Prestado
| Campo | Origem | Formato |
|---|---|---|
| Código de Tributação Nacional / Municipal | `DPS/serv/cServ/cTribNac` + `cTribMun` | `01.07.01 / -`, Lim. 14 |
| Código da NBS | `DPS/serv/cServ/cNBS` | `1.1501.30.00` |
| Local da Prestação / Sigla UF / País | `xLocPrestacao` + UF (de `DPS/serv/locPrest/cLocPrestacao` pela tabela) + `DPS/serv/locPrest/cPaisPrestacao` ou `BR` | `Jaraguá / GO / BR`, Lim. 42 |
| (sem label) Descrição do código | `xTribMun` quando não vazio; senão `xTribNac` | Lim. 170 |
| Descrição do Serviço | `DPS/serv/cServ/xDescServ` | Lim. 1300 |

### Tributação Municipal (ISSQN)
Sem `DPS/valores/trib/tribMun`, ou com `tribISSQN` = 4, o bloco vira uma linha só:
`TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN`.
| Linha | Campos (origem) |
|---|---|
| 1 | Tipo de Tributação do ISSQN (`DPS/valores/trib/tribMun/tribISSQN`, descrição) · Município / Sigla UF / País de Incidência do ISSQN (`xLocIncid` + UF de `cLocIncid` + `DPS/valores/trib/tribMun/cPaisResult` ou `BR`; ocupa 2 colunas) |
| 2 (nota 5) | Regime Especial de Tributação do ISSQN (`DPS/prest/regTrib/regEspTrib`, descrição) · Tipo de Imunidade do ISSQN (`tribMun/tpImunidade`, descrição, Lim. 40) · Suspensão da Exigibilidade do ISSQN (`tribMun/exigSusp/tpSusp`, descrição) · Número Processo Suspensão (`tribMun/exigSusp/nProcesso`) |
| 3 (nota 5) | Benefício Municipal (`valores/tpBM`, como está, Lim. 40) · Cálculo do BM (`valores/vCalcBM` ou `DPS/valores/trib/tribMun/BM/vRedBCBM`, R$) · Total Deduções/Reduções (`DPS/valores/vDedRed/vDR` ou `valores/vCalcDR`, R$) · Desconto Incondicionado (`DPS/valores/vDescCondIncond/vDescIncond`, R$) |
| 4 | BC ISSQN (`valores/vBC`, R$) · Alíquota Aplicada (`valores/pAliqAplic`, %) · Retenção do ISSQN (`tribMun/tpRetISSQN`, descrição) · ISSQN Apurado (`valores/vISSQN`, R$) |

**Nota 5:** as linhas 2 e 3 somem quando **todos** os campos da linha estão sem informação no XML.

### Tributação Federal (Exceto CBS)
| Linha | Campos (origem em `DPS/valores/trib/tribFed/`) |
|---|---|
| 1 | IRRF (`vRetIRRF`, R$) · Contribuição Previdenciária - Retida (`vRetCP`, R$) · Contribuições Sociais - Retidas (R$, ver regra) |
| 2 (nota 6) | PIS - Débito Apuração Própria (R$, ver regra) · COFINS - Débito Apuração Própria (R$, ver regra) · Descrição Contrib. Sociais - Retidas (`piscofins/tpRetPisCofins`, descrição, ocupa 2 colunas) |

Regra do `tpRetPisCofins` (NT v1.02):
- Com `tpRetPisCofins` = 1: Contribuições Sociais - Retidas = `vRetCSLL` + `piscofins/vPis` + `piscofins/vCofins`, e PIS e COFINS próprios = `R$ 0,00`.
- Nos demais casos: Contribuições Sociais - Retidas = `vRetCSLL`, PIS = `vPis` e COFINS = `vCofins`.
- Na soma, campo ausente vale zero. Se os três estiverem ausentes, mostra "-".

**Nota 6:** a linha 2 só sai quando `DPS/dCompet` ≤ `2026-12-31`.

### Tributação IBS / CBS
Os caminhos ficam em `IBSCBS/` (o da NFS-e, em infNFSe) e `DPS/IBSCBS/`. As NFS-e de 2026 sem
IBSCBS mostram "-" em todos os campos, e o bloco continua aparecendo.
| Linha | Campos |
|---|---|
| 1 | CST / cClassTrib (`DPS/IBSCBS/valores/trib/gIBSCBS/CST` + `cClassTrib`, `nnn / nnnnnn`) · Indicador de Operação / Código IBGE / Município / UF (`DPS/IBSCBS/cIndOp` + `IBSCBS/cLocalidadeIncid` + `IBSCBS/xLocalidadeIncid` + UF da tabela; ocupa 2 colunas, Lim. 56) |
| 2 | Exclusões e Reduções da Base de Cálculo (soma de `DPS/valores/vDescCondIncond/vDescIncond` + `IBSCBS/valores/vCalcReeRepRes` + `valores/vISSQN` + `DPS/valores/trib/tribFed/piscofins/vPis` + `vCofins`, R$) · Base de Cálculo Após Exclusões e Reduções (`IBSCBS/valores/vBC`, R$) · Red. Alíquota IBS / Red. Alíquota CBS (`IBSCBS/valores/uf/pRedAliqUF` / `mun/pRedAliqMun` / `fed/pRedAliqCBS`, `%/%/%`) · Alíquota IBS UF / IBS Mun (`IBSCBS/valores/uf/pIBSUF` / `mun/pIBSMun`, `%/%`) |
| 3 | Alíq. Efetiva Municipal - IBS (`IBSCBS/valores/mun/pAliqEfetMun`) · Valor Apurado Municipal - IBS (`IBSCBS/totCIBS/gIBS/gIBSMunTot/vIBSMun`) · Alíq. Efetiva Estadual - IBS (`IBSCBS/valores/uf/pAliqEfetUF`) · Valor Apurado Estadual - IBS (`IBSCBS/totCIBS/gIBS/gIBSUFTot/vIBSUF`) |
| 4 | Valor Total Apurado - IBS (`IBSCBS/totCIBS/gIBS/vIBSTot`) · Alíquota - CBS (`IBSCBS/valores/fed/pCBS`) · Alíquota Efetiva - CBS (`IBSCBS/valores/fed/pAliqEfetCBS`) · Valor Total Apurado - CBS (`IBSCBS/totCIBS/gCBS/vCBS`) |

Sem `IBSCBS` no XML, a soma das exclusões mostra "-", não "R$ 0,00".

### Valor Total da NFS-e (labels "VALOR DA OPERAÇÃO / SERVIÇO" e "VALOR LÍQUIDO DA NFS-e" em caixa alta)
| Linha | Campos |
|---|---|
| 1 | Valor da Operação / Serviço (`DPS/valores/vServPrest/vServ`) · Desconto Incondicionado (`DPS/valores/vDescCondIncond/vDescIncond`) · Desconto Condicionado (`.../vDescCond`) |
| 2 | Total das Retenções (ISSQN / Federais) (`valores/vTotalRet`) · Valor Líquido da NFS-e (`valores/vLiq`) · Total do IBS/CBS (`IBSCBS/totCIBS/gIBS/vIBSTot` + `IBSCBS/totCIBS/gCBS/vCBS`; sem os dois, "-") · **Valor Líquido da NFS-e + IBS/CBS** (`IBSCBS/totCIBS/vTotNF`; sem ele, "-"; célula sombreada) |

### Informações Complementares (Lim. 2000)
São itens separados por ` | `, nesta ordem, e só entram os que existem:
`Inf. Cont.: ` + `DPS/serv/infoCompl/xInfComp` · `NFS-e Subst.: ` + `DPS/subst/chSubstda` ·
`Doc. Ref.: ` + `DPS/serv/infoCompl/docRef` · `Cod. Obra: ` + `DPS/serv/obra/cObra` ·
`Insc. Imob.: ` + `DPS/serv/obra/inscImobFisc` · `Cod. Evt.: ` + `DPS/serv/atvEvento/idAtvEvt` ·
`Doc. Tec.: ` + `DPS/serv/infoCompl/idDocTec` · `Núm. Ped.: ` + `DPS/serv/infoCompl/gItemPed/xPed` ·
`Item Ped.: ` + `.../xItemPed` · `Inf. A. T. Mun.: ` + `xOutInf`.

Quando a soma desses itens passa de 1997 caracteres, eles são cortados com "...". A linha dos totais
aproximados dos tributos (nota 10) vem **sempre no fim**, nunca é cortada e fica em linha própria:
- com `totTrib/vTotTrib`: `Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: R$ 0,00; Estaduais: R$ 0,00; Municipais: R$ 0,00`
- com `totTrib/pTotTrib`: mesma frase com `pTotTribFed`, `pTotTribEst` e `pTotTribMun` em `%`
- com `totTrib/pTotTribSN`: `Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Simples Nacional: 6,00%`
- com `totTrib/indTotTrib` ou sem `totTrib`: `Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: -`

(o caminho é `DPS/valores/trib/totTrib/`)

### Marca d'água (NT 2.5.1 e 2.5.2)
Com `cancelada`, sai `CANCELADA`. Com `substituida`, sai `SUBSTITUÍDA`. Se as duas vierem, vale
`CANCELADA`. A marca fica na diagonal, em Arial (Helvetica no PDF), tamanho de pelo menos 50pt
(usar 80pt), cinza K35 (`#a6a6a6`) e por cima do conteúdo.

## Tabelas (Anexo I v1.01)
- ambGer: 1 Sistema Próprio do Município · 2 Sefin Nacional NFS-e
- tpAmb: 1 Produção · 2 Homologação
- tpEmit: 1 Prestador · 2 Tomador · 3 Intermediário
- cStat: 100 NFS-e Gerada · 102 NFS-e de Decisão Judicial ou Administrativa · 103 NFS-e Avulsa · 107 NFS-e MEI
- finNFSe: 0 NFS-e regular
- opSimpNac: 1 Não Optante · 2 Optante - Microempreendedor Individual (MEI) · 3 Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)
- regApTribSN: 1 Regime de apuração dos tributos federais e municipal pelo SN · 2 Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo · 3 Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo
- regEspTrib: 0 Nenhum · 1 Ato Cooperado (Cooperativa) · 2 Estimativa · 3 Microempresa Municipal · 4 Notário ou Registrador · 5 Profissional Autônomo · 6 Sociedade de Profissionais · 9 Outros
- tribISSQN: 1 Operação Tributável · 2 Imunidade · 3 Exportação de Serviço · 4 Não Incidência
- tpImunidade: 0 Imunidade (tipo não informado na nota de origem) · 1 Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a) · 2 Entidades religiosas e templos de qualquer culto, inclusive suas organizações assistenciais e beneficentes (CF88, Art 150, VI, b) · 3 Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos, atendidos os requisitos da lei (CF88, Art 150, VI, c) · 4 Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d) · 5 Fonogramas e videofonogramas musicais produzidos no Brasil contendo obras musicais ou literomusicais de autores brasileiros e/ou obras em geral interpretadas por artistas brasileiros bem como os suportes materiais ou arquivos digitais que os contenham (CF88, Art 150, VI, e)
- tpSusp: 1 Exigibilidade Suspensa por Decisão Judicial · 2 Exigibilidade Suspensa por Processo Administrativo
- tpRetISSQN: 1 Não Retido · 2 Retido pelo Tomador · 3 Retido pelo Intermediário
- tpRetPisCofins: 0 PIS/COFINS/CSLL Não Retidos · 1 PIS/COFINS Retido · 2 PIS/COFINS Não Retido · 3 PIS/COFINS/CSLL Retidos · 4 PIS/COFINS Retidos, CSLL Não Retido · 5 PIS Retido, COFINS/CSLL Não Retido · 6 COFINS Retido, PIS/CSLL Não Retido · 7 PIS Não Retido, COFINS/CSLL Retidos · 8 PIS/COFINS Não Retidos, CSLL Retido · 9 COFINS Não Retido, PIS/CSLL Retidos

Código fora da tabela: imprime o próprio código.

## Leiaute (NT 2.2 a 2.4 e Anexo I)

O modelo visual de referência é o Anexo I da NT. A versão em imagem está em `docs/danfse-modelo.png`.

- **Página e borda:** A4 retrato, uma página só e obrigatoriamente uma. `@page { margin: 0.3cm; }`. O
  corpo tem 20,40cm de largura e borda de **1pt**, e as linhas que separam os blocos têm **0,5pt**.
- **Sombreamento:** cinza 5% (`#f2f2f2`) no cabeçalho, na célula de título de cada bloco (a 1ª
  célula, com o nome do bloco), em "Emitente da NFS-e" e em "Valor Líquido da NFS-e + IBS/CBS". O
  resto fica com fundo branco.
- **Fontes:** a NT pede Arial nos títulos e Microsoft Sans Serif no conteúdo. São fontes
  proprietárias, então o PDF usa a fonte padrão `Helvetica` do dompdf, que tem as métricas do Arial
  e não precisa ser embutida.
  - título do bloco: 7pt, negrito, caixa alta;
  - label de campo: 6pt, negrito, primeira letra de cada palavra maiúscula. Os labels dos
    "Dados da NFS-e" são exceção: 7pt, negrito, caixa alta;
  - conteúdo: 7pt, normal, preto.
- **Grade:** cada bloco é uma `<table>` de largura total com `table-layout: fixed` e 4 colunas de 25%
  (5,10cm). Cada célula traz o label em cima e o valor embaixo, como no modelo. A linha tem 0,63cm
  de altura mínima (0,67cm nos "Dados da NFS-e" e em "Valor Total"). Na 1ª linha do bloco, a 1ª
  célula é o título (sombreado), e nas linhas seguintes ela é um campo normal. A ordem das células
  por linha segue o Anexo I:
  - **Prestador:** [título][CNPJ/CPF/NIF][Indicador Municipal][Telefone] / [Nome (2 col)][Município/UF][Código IBGE/CEP] / [Endereço (2 col)][E-mail (2 col)] / [Simples Nacional][Regime de Apuração (3 col)]
  - **Tomador:** igual, sem a 4ª linha.
  - **Destinatário:** [título][CNPJ/CPF/NIF][vazio][Telefone] / [Nome (2 col)][Município/UF][Código IBGE/CEP] / [Endereço (2 col)][E-mail (2 col)]
  - **Intermediário:** igual ao tomador.
  - **Serviço:** [título][Código de Tributação][Código da NBS][Local da Prestação] / [descrição do código (4 col, sem label)] / [Descrição do Serviço (4 col)]
- **Cabeçalho:** 3 áreas.
  - esquerda: o logotipo `resources/danfse/logo-nfse.png`, com 4,00cm × 0,85cm, embutido como data URI base64;
  - centro: `DANFSe v2.0` e, embaixo, `Documento Auxiliar da NFS-e`, em negrito 9pt centralizado. Na homologação vem ainda `NFS-e SEM VALIDADE JURÍDICA` em negrito 9pt vermelho (`#ff0000`);
  - direita: `Município: X / UF` em 8pt, depois `Ambiente Gerador: ...` e `Tipo de Ambiente: ...` em 6pt.
- **Dados da NFS-e:** 3 colunas de campos à esquerda e o QR Code à direita.
  - campos: Chave de Acesso (linha inteira das 3 colunas) / Número · Competência · Data e Hora da NFS-e / Número da DPS · Série · Data e Hora da DPS / Emitente (sombreado) · Situação · Finalidade;
  - QR Code: PNG em data URI com no mínimo 1,52cm (usar **2,0cm × 2,0cm**). Abaixo dele vai, em 6pt e 3 linhas: `A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e`.
- **Blocos de uma linha só** (tomador, destinatário ou intermediário não identificados, ISSQN não sujeito): o título fica com o texto na linha inteira e altura de pelo menos 0,32cm.
- **Altura variável:** a Descrição do Serviço tem altura mínima de 3,0cm e Informações Complementares de 2,0cm. Os textos longos quebram linha, mas o total precisa caber numa página.
- **Canhoto:** fica de fora. É opcional pela NT 2.3.3.

## Testes esperados (`tests/Danfse/`)

Os XMLs de `tests/fixtures/danfse/` são de NFS-e reais, com o tomador, as chaves e as assinaturas
trocados por dados fictícios.
- `DanfseDadosTest`:
  - para cada fixture: chave, número, competência, datas, CNPJ ou CPF com máscara, município pela tabela, `01.07.01 / -`, `1.1501.30.00`, valores em R$, descrições de tpEmit, cStat, opSimpNac, regApTribSN e tribISSQN, e a linha dos totais aproximados;
  - destinatário "NÃO IDENTIFICADO" quando não há IBSCBS;
  - intermediário "NÃO IDENTIFICADO";
  - nota 5: linhas de benefício somem;
  - nota 6: linha do PIS presente com competência em 2026 e ausente com competência em 2027 (altere `dCompet` no XML do teste);
  - tpRetPisCofins = 1 soma as contribuições e zera PIS e COFINS (monte o XML no teste);
  - truncamento com "...";
  - homologação: aviso presente;
  - `cTribNac` com item 99 esconde o município do cabeçalho;
  - XML inválido lança `NfseNacionalException`.
- `DanfseGeradorTest`:
  - `gerarPdf` devolve bytes que começam com `%PDF` e têm **exatamente 1 página** (contar `/Type /Page` sem o `s`, ou usar a contagem de páginas do dompdf), para as 3 fixtures e para uma com `xDescServ` de 1300 caracteres e `xInfComp` de 2000;
  - o HTML tem o QR em data URI e a URL de consulta com a chave, e o HTML de `cancelada` traz `CANCELADA`.

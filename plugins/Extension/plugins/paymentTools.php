<?php

namespace plugins\router\Extension;

class paymentTools
{
    /**
     * Extrai códigos Pix Copia e Cola (BR Code / EMV) completos de um corpo HTML.
     *
     * Funciona independentemente de o código estar dentro de um atributo de um
     * input (value="...") ou dentro de um elemento normal como div, span, p,
     * textarea, etc. A busca é feita diretamente sobre o conteúdo bruto,
     * localizando o token EMV que contém o identificador "br.gov.bcb.pix" e
     * termina no campo CRC ("6304" seguido de 4 caracteres hexadecimais).
     *
     * @param string $body Conteúdo HTML (ou texto) a ser inspecionado.
     * @return string[] Lista de códigos Pix completos encontrados (sem duplicatas).
     */
    public static function findFullValuePixPayment(string $body): array
    {
        $fullCompletePix = [];

        // O BR Code começa no payload EMV ("000201") e termina no campo CRC
        // ("6304" + 4 caracteres hexadecimais). Entre eles pode haver espaços e
        // acentos (nome do recebedor/cidade), por isso aceitamos qualquer
        // caractere, exceto os que delimitam tags ou atributos HTML
        // (< > " '), garantindo que a captura fique restrita a um único
        // input, div, span, etc.
        $pattern = '~000201[^<>"\x27]*?6304[0-9A-Fa-f]{4}~';

        if (preg_match_all($pattern, $body, $matches) && !empty($matches[0])) {
            foreach ($matches[0] as $match) {
                $match = trim($match);
                // Considera apenas códigos Pix de fato (contêm o identificador).
                if ($match === '' || stripos($match, 'br.gov.bcb') === false) {
                    continue;
                }
                if (!in_array($match, $fullCompletePix, true)) {
                    $fullCompletePix[] = $match;
                }
            }
        }

        return $fullCompletePix;
    }

    /**
     * Faz o parsing TLV (Tag-Length-Value) de um payload EMV do Pix.
     *
     * Cada campo segue o formato: ID(2 dígitos) + LEN(2 dígitos) + VALUE(LEN).
     *
     * @param string $payload Trecho EMV a ser interpretado.
     * @return array<string,string> Mapa "id => valor" dos campos encontrados.
     */
    public static function parseEmvTlv(string $payload): array
    {
        $fields = [];
        $i = 0;
        $len = strlen($payload);

        while ($i + 4 <= $len) {
            $id = substr($payload, $i, 2);
            $size = substr($payload, $i + 2, 2);

            // IDs e tamanhos devem ser numéricos; se não forem, o trecho não é
            // um TLV válido a partir daqui, então interrompemos.
            if (!ctype_digit($id) || !ctype_digit($size)) {
                break;
            }

            $size = (int) $size;
            $value = substr($payload, $i + 4, $size);

            // Caso o tamanho informado ultrapasse o restante da string, paramos
            // para evitar leitura inconsistente.
            if (strlen($value) < $size) {
                break;
            }

            $fields[$id] = $value;
            $i += 4 + $size;
        }

        return $fields;
    }

    /**
     * Interpreta um código Pix Copia e Cola e devolve suas informações de forma
     * estruturada.
     *
     * Trata tanto o Pix estático (valor embutido no campo 54) quanto o Pix
     * dinâmico ("Pix de link"), onde o valor não fica no código e sim na URL
     * indicada no campo 25 da conta do recebedor (campos 26 a 51).
     *
     * @param string $pixCode BR Code completo.
     * @return array{
     *     valor: ?string,
     *     recebedor: ?string,
     *     cidade: ?string,
     *     txid: ?string,
     *     url: ?string,
     *     dinamico: bool
     * }
     */
    public static function extractPixInfo(string $pixCode): array
    {
        $fields = self::parseEmvTlv($pixCode);

        $info = [
            'valor' => null,
            'recebedor' => null,
            'cidade' => null,
            'txid' => null,
            'url' => null,
            'dinamico' => false,
        ];

        // Valor da transação (campo 54). Quando ausente, normalmente trata-se de
        // um Pix dinâmico cujo valor só existe no link.
        if (isset($fields['54']) && $fields['54'] !== '') {
            $info['valor'] = $fields['54'];
        }

        if (isset($fields['59']) && $fields['59'] !== '') {
            $info['recebedor'] = trim($fields['59']);
        }

        if (isset($fields['60']) && $fields['60'] !== '') {
            $info['cidade'] = trim($fields['60']);
        }

        // txid fica dentro do template de dados adicionais (campo 62, subcampo 05).
        if (isset($fields['62']) && $fields['62'] !== '') {
            $additional = self::parseEmvTlv($fields['62']);
            if (isset($additional['05']) && $additional['05'] !== '') {
                $info['txid'] = $additional['05'];
            }
        }

        // A conta do recebedor pode estar em qualquer campo de 26 a 51. Procuramos
        // a URL do Pix dinâmico (subcampo 25) dentro desses templates.
        for ($id = 26; $id <= 51; $id++) {
            $key = (string) $id;
            if (empty($fields[$key])) {
                continue;
            }
            $account = self::parseEmvTlv($fields[$key]);
            if (!empty($account['25'])) {
                $url = $account['25'];
                if (stripos($url, 'http') !== 0) {
                    $url = 'https://' . $url;
                }
                $info['url'] = $url;
                $info['dinamico'] = true;
            }
        }

        return $info;
    }

    /**
     * Tenta obter o valor de um Pix dinâmico a partir da URL do payload.
     *
     * A URL costuma devolver um JWS (três segmentos base64url separados por
     * ponto) ou um JSON. Em ambos os casos buscamos o valor original da cobrança.
     *
     * @param string $url URL extraída do código Pix dinâmico.
     * @return string|null Valor encontrado (ex.: "10.50") ou null se indisponível.
     */
    public static function fetchDynamicPixValue(string $url): ?string
    {
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($curl);


        if (!is_string($body) || $body === '') {
            return null;
        }

        $payload = $body;

        // Se vier um JWS (header.payload.signature), decodifica o segmento central.
        if (substr_count($body, '.') >= 2) {
            $segments = explode('.', $body);
            $decoded = base64_decode(strtr($segments[1], '-_', '+/'), true);
            if ($decoded !== false && $decoded !== '') {
                $payload = $decoded;
            }
        }

        $json = json_decode($payload, true);
        if (is_array($json)) {
            // Estrutura padrão da API Pix: valor.original.
            if (isset($json['valor']['original'])) {
                return (string) $json['valor']['original'];
            }
            if (isset($json['valor']) && is_scalar($json['valor'])) {
                return (string) $json['valor'];
            }
        }

        // Tentativa final via regex, cobrindo formatos não previstos.
        if (preg_match('~"original"\s*:\s*"?([0-9]+\.[0-9]{2})"?~', $payload, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Monta um relatório de debug legível para todos os códigos Pix de um corpo.
     *
     * Para cada código mostra o valor e as principais informações. Quando o
     * valor não está no código (Pix dinâmico/link), tenta resolvê-lo acessando a
     * URL embutida no payload.
     *
     * @param string $body Conteúdo HTML/texto a ser inspecionado.
     * @return array<int,array{
     *     pix: int,
     *     tipo: string,
     *     valor: ?string,
     *     origemValor: ?string,
     *     recebedor: ?string,
     *     cidade: ?string,
     *     txid: ?string,
     *     url: ?string,
     *     copiaECola: string
     * }> Lista de relatórios estruturados (vazia se nada for achado).
     */
    public static function debugPixPayment(string $body): array
    {
        $codes = self::findFullValuePixPayment($body);
        if (empty($codes)) {
            return [];
        }

        $report = [];
        foreach ($codes as $index => $code) {
            $info = self::extractPixInfo($code);

            $valor = $info['valor'];
            $origemValor = $valor !== null ? 'código' : null;

            // Pix de link: o valor só aparece no link, então buscamos lá.
            if ($valor === null && $info['dinamico'] && !empty($info['url'])) {
                $valor = self::fetchDynamicPixValue($info['url']);
                if ($valor !== null) {
                    $origemValor = 'link';
                }
            }

            $tipo = $info['dinamico'] ? 'dinâmico (link)' : 'estático';

            $report[] = [
                'pix' => $index + 1,
                'tipo' => $tipo,
                'valor' => ($valor !== null && $valor !== '') ? $valor : null,
                'origemValor' => $origemValor,
                'recebedor' => $info['recebedor'],
                'cidade' => $info['cidade'],
                'txid' => $info['txid'],
                'url' => $info['url'],
                'copiaECola' => $code,
            ];
        }

        return $report;
    }
}
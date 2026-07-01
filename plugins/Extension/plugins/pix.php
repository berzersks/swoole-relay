<?php

final class PixCopiaECola
{
    private string $chavePix;
    private string $nomeRecebedor;
    private string $cidadeRecebedor;
    private float $valor;
    private string $txid;
    private ?string $descricao;

    public function __construct(
        string $chavePix,
        string $nomeRecebedor,
        string $cidadeRecebedor,
        float $valor,
        string $txid = '***',
        ?string $descricao = null
    ) {
        $this->chavePix = trim($chavePix);
        $this->nomeRecebedor = $this->limparTexto($nomeRecebedor, 25);
        $this->cidadeRecebedor = $this->limparTexto($cidadeRecebedor, 15);
        $this->valor = $valor;
        $this->txid = $this->limparTxid($txid);
        $this->descricao = $descricao !== null
            ? $this->limparTexto($descricao, 72)
            : null;
    }

    public function gerar(): string
    {
        $payload = '';

        // 00 - Payload Format Indicator
        $payload .= $this->campo('00', '01');

        // 01 - Point of Initiation Method
        // 11 = estático
        $payload .= $this->campo('01', '11');

        // 26 - Merchant Account Information
        $merchantAccount = '';
        $merchantAccount .= $this->campo('00', 'br.gov.bcb.pix');
        $merchantAccount .= $this->campo('01', $this->chavePix);

        if (!empty($this->descricao)) {
            $merchantAccount .= $this->campo('02', $this->descricao);
        }

        $payload .= $this->campo('26', $merchantAccount);

        // 52 - Merchant Category Code
        $payload .= $this->campo('52', '0000');

        // 53 - Moeda BRL
        $payload .= $this->campo('53', '986');

        // 54 - Valor
        if ($this->valor > 0) {
            $payload .= $this->campo('54', number_format($this->valor, 2, '.', ''));
        }

        // 58 - País
        $payload .= $this->campo('58', 'BR');

        // 59 - Nome do recebedor
        $payload .= $this->campo('59', $this->nomeRecebedor);

        // 60 - Cidade do recebedor
        $payload .= $this->campo('60', $this->cidadeRecebedor);

        // 62 - Dados adicionais
        $additionalData = $this->campo('05', $this->txid);
        $payload .= $this->campo('62', $additionalData);

        // 63 - CRC16
        $payloadSemCrc = $payload . '6304';
        $crc = $this->crc16($payloadSemCrc);

        return $payloadSemCrc . $crc;
    }

    private function campo(string $id, string $valor): string
    {
        $tamanho = str_pad((string) strlen($valor), 2, '0', STR_PAD_LEFT);

        return $id . $tamanho . $valor;
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        $polinomio = 0x1021;

        $bytes = unpack('C*', $payload);

        foreach ($bytes as $byte) {
            $crc ^= ($byte << 8);

            for ($i = 0; $i < 8; $i++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ $polinomio) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function limparTexto(string $texto, int $limite): string
    {
        $texto = trim($texto);

        $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        if ($convertido !== false) {
            $texto = $convertido;
        }

        $texto = strtoupper($texto);

        // Remove caracteres que costumam dar problema em app bancário.
        $texto = preg_replace('/[^A-Z0-9 $%*+\-.\/:]/', '', $texto);

        return substr($texto ?? '', 0, $limite);
    }

    private function limparTxid(string $txid): string
    {
        $txid = trim($txid);

        if ($txid === '') {
            return '***';
        }

        // TXID no Pix estático costuma aceitar até 25 caracteres.
        $txid = preg_replace('/[^A-Za-z0-9*\-_.]/', '', $txid);

        return substr($txid ?: '***', 0, 25);
    }
}
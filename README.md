# Sistema de Proxy Web e Interceptação de Dados

Este sistema funciona como um proxy intermediário para requisições web, permitindo a interceptação, modificação e monitoramento do tráfego entre o cliente e o servidor remoto. O projeto foi desenvolvido com Swoole PHP e oferece recursos avançados para testes, desenvolvimento e análise de segurança.

## Características Principais

- **Proxy de Requisições**: Redireciona todas as solicitações para um endereço remoto configurável
- **Injeção de Código**: Permite inserir scripts personalizados nas páginas retornadas
- **Captura de Dados**: Registra inputs, armazenamento local e cookies dos usuários
- **Cache de Recursos**: Armazena localmente imagens e outros recursos para melhorar a performance
- **Manipulação de Cookies**: Gerencia e modifica cookies entre cliente e servidor
- **Endpoints Personalizados**: Configura diferentes destinos baseados no host da requisição
- **Políticas de CORS**: Ajusta automaticamente cabeçalhos de controle de acesso

## Pré-requisitos

- PHP 8.0 ou superior
- Extensão Swoole PHP instalada
- Servidor Linux/Unix (recomendado)
- Certificados SSL/TLS para modo HTTPS (incluídos no repositório)

## Instalação

1. Clone o repositório:
   ```bash
   git clone [URL_DO_REPOSITÓRIO]
   cd [NOME_DO_DIRETÓRIO]
   ```

2. Instale a extensão Swoole PHP (se ainda não estiver instalada):
   ```bash
   pecl install swoole
   ```

3. Verifique as permissões dos diretórios:
   ```bash
   chmod -R 755 plugins
   mkdir -p cookies captured
   chmod 777 cookies captured
   ```

## Configuração

A configuração principal é feita através do arquivo `plugins/configInterface.json`. Este arquivo contém todas as configurações necessárias para o funcionamento do proxy, divididas em várias seções.

### Parâmetros de Configuração - serverSettings

Estas configurações controlam o comportamento do servidor Swoole:

| Parâmetro | Descrição | Valor padrão |
|-----------|-----------|--------------|
| `worker_num` | Número de processos de trabalho (workers) | `1` |
| `max_request` | Número máximo de requisições antes do worker reiniciar | `20000000` |
| `enable_coroutine` | Ativa o modo de corrotinas para melhor performance | `true` |
| `http_compression` | Ativa a compressão HTTP de respostas | `true` |
| `max_coroutine` | Número máximo de corrotinas simultâneas | `20000000` |
| `enable_reuse_port` | Permite reutilização da porta TCP entre processos | `false` |
| `open_cpu_affinity` | Vincula workers a CPUs específicas para otimização | `false` |
| `max_request_grace` | Tempo de graça antes de encerrar worker após max_request | `600000` |
| `open_tcp_keepalive` | Ativa o mecanismo TCP keepalive | `false` |
| `http_compression_level` | Nível de compressão HTTP (1-9) | `3` |
| `ssl_cert_file` | Caminho para o arquivo de certificado SSL | `server.crt` |
| `ssl_key_file` | Caminho para o arquivo de chave privada SSL | `server.key` |

### Parâmetros de Configuração - server

Estes parâmetros controlam o comportamento do proxy:

| Parâmetro | Descrição | Exemplo |
|-----------|-----------|---------|
| `endPoints` | Mapeamento de hosts locais para URLs remotas específicas | `{"api.localhost": "https://api.site-alvo.com"}` |
| `remoteAddress` | URL do site/serviço alvo principal | `https://site-alvo.com` |
| `localPortListener` | Porta na qual o servidor irá escutar | `443` |
| `autoGenerateSslCertificate` | Se deve gerar certificados SSL automaticamente | `true` |
| `currentDomain` | Domínio local do seu proxy | `https://localhost` |
| `accessPolicy` | Controla a manipulação automática das políticas CORS | `true` |
| `enableCache` | Ativa/desativa o cache de recursos estáticos | `false` |
| `extraReplace` | Lista de padrões de texto a serem substituídos nas respostas | Ver abaixo |
| `injection` | Regras para injeção de código baseada em condições | Ver abaixo |
| `pixKey` | Chave PIX para integrações com pagamentos (específico para Brasil) | `brasil...` |
| `discountForPayments` | Percentual de desconto para pagamentos específicos | `35` |

#### Detalhamento dos parâmetros mais importantes:

#### endPoints
Define os mapeamentos entre domínios locais e seus alvos remotos. Cada chave representa um subdomínio local que será mapeado para o URL remoto correspondente.

# Studio Bailare · Portfólio

Sistema web para gestão de um estúdio de dança: agenda, cadastros, comunicação e fluxo de ingressos por data, desenvolvido com PHP, MySQL e JavaScript.

**Edição demonstrativa sanitizada.** Não contém dados de alunos ou compradores, credenciais, banco de produção ou imagens do evento real. A prévia usa dados fictícios; compras e envio real de e-mail estão bloqueados nesta edição.

## O problema

Centralizar a rotina do estúdio em uma interface acessível no computador e no celular, distinguindo permissões da equipe e permitindo conferir pagamentos e validar entradas.

## Funcionalidades representadas no código

- Calendário de aulas, eventos em mais de uma data, lembretes e contatos.
- Cadastros de alunos, professores e turmas; Gestão e Secretaria com permissões distintas.
- Galeria ampliada com navegação e gestos no celular.
- Pedidos com quantidade, data escolhida, e-mail e WhatsApp do responsável.
- Cálculo de Pix por pedido e conferência **manual** do pagamento.
- Ingresso individual com QR Code e controle de entrada por data.
- Leitura pela câmera na portaria autenticada e confirmação explícita da entrada.
- Lotação por dia, auditoria, notificações SMTP e backups privados.

A prévia demonstra o frontend com exemplos. O backend está disponível para revisão técnica, sem painel administrativo público ou login demonstrativo.

## Tecnologias e decisões

| Área | Implementação |
| --- | --- |
| Backend | PHP 8.2, PDO, consultas parametrizadas e MySQL/InnoDB |
| Frontend | HTML, CSS e JavaScript sem framework |
| Acesso | Sessões, permissões, CSRF, senha com hash e tokens revogáveis |
| Bilheteria | Valores em centavos, transações e capacidade por data |
| QR Code | QRCode.js para geração e jsQR para leitura no aparelho |
| E-mail | PHPMailer com SMTP autenticado e validação TLS |

O fluxo separa pedido, pagamento conferido e ingresso emitido: gerar Pix não comprova recebimento. Falhas de notificação são isoladas para não esconder pedidos nem desfazer registros já gravados.

## Executar a prévia

Com PHP 8.2 instalado, na raiz do projeto:

```sh
php -S 127.0.0.1:8095 -t public
```

Abra `http://127.0.0.1:8095/`. Não é necessário banco para visualizar a página demonstrativa. O servidor de desenvolvimento deve ficar no computador local.

## Estrutura

```text
public/    Frontend demonstrativo e módulos PHP
  assets/  Arte SVG ilustrativa, sem fotografias reais
  mailer/  PHPMailer e licença
tests/     Verificações de isolamento da demonstração
scripts/   Preparação de pacote local
docs/      Arquitetura, execução e segurança
```

## Verificações

```sh
php tests/portfolio.php
node --check public/app.js
node scripts/build.mjs
```

O teste verifica os bloqueios de compra e SMTP e os dados fictícios. O build vai para `dist/`, ignorado pelo Git. Não é uma suíte completa de segurança ou concorrência.

## Limitações e evolução

- Backend ainda tem partes acopladas e requer configuração específica para implantação real.
- Não há consulta automática ao banco para confirmar Pix.
- Próximos passos: configuração centralizada, migrações versionadas, testes de integração em MySQL e acessibilidade.
- Fontes vêm do Google Fonts, com alternativas locais quando offline.
- Esta edição não deve substituir o site de produção. Nenhum deploy automático ou GitHub Pages foi configurado.

Leia [Arquitetura e execução](docs/INSTALACAO.md) e [Segurança](docs/SEGURANCA.md).

## Desenvolvimento e licenças

Projeto de Bruno Dorneles, desenvolvido com apoio de ferramentas de IA e validação iterativa de requisitos e fluxos. Este repositório é uma edição de portfólio, não o ambiente operacional.

Não foi atribuída licença de código aberto ao código próprio. Publicar o repositório não concede automaticamente licença de reutilização. As licenças das bibliotecas são preservadas em `public/mailer/LICENSE`, `public/qrcode-LICENSE.txt` e `public/jsQR-LICENSE.txt`. Fotografias e cartaz reais não integram esta edição.

# Arquitetura e execução

Execute `php -S 127.0.0.1:8095 -t public` com PHP 8.2 e abra o endereço local. `app.js` usa exemplos fictícios e não consulta o servidor original.

Os botões da equipe apontam para o painel, mas não há login demonstrativo. O instalador permanece bloqueado; nenhum código de ativação acompanha o repositório. Domínio, Pix, SMTP e banco contêm placeholders.

## Módulos

- `admin.php`: inicialização, sessão, autorização, cadastros e rotas.
- `pix.php`: pedidos, valores em centavos, BR Code e conferência manual.
- `tickets.php`: emissão individual, validação por data e notificações.
- `capacity.php`: capacidade por data e reserva transacional.
- `gallery.php`: validação e entrega de imagens.
- `remember.php`: tokens revogáveis, sem senha no navegador.
- `backup.php` e `backup-cron.php`: backup privado e agendamento autenticado.

O sistema original usa tabelas para usuários, registros, pedidos, ingressos, entradas, auditoria, capacidade e estado de notificações. Nenhum dump acompanha este repositório.

## Bloqueios intencionais

`pixOpenEvent()` retorna falso para impedir compras. `ticketSmtp()` interrompe envios antes de conectar. Contatos usam o domínio reservado `.invalid`.

## Build

`node scripts/build.mjs` copia a prévia para `dist/`. Não faz deploy, não cria banco, não gera credenciais e não ativa vendas.

GitHub Pages não executa PHP/MySQL. Esta edição não é um pacote pronto para produção: seria necessário centralizar parâmetros privados, revisar o instalador, provisionar um banco isolado e executar testes de integração e restauração. Não publique esta edição sobre um site existente.

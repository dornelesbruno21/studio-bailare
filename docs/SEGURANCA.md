# Segurança e dados

- Não enviar `config.php`, `smtp.json`, `backup-cron.json`, códigos de instalação, `.env`, dumps SQL, bancos SQLite, backups, logs ou fotos/cadastros dos alunos ao GitHub.
- O `.gitignore` ajuda a prevenir inclusão acidental; não remove segredos já publicados no histórico. Se isso ocorrer, revogue/troque a credencial e trate o histórico separadamente.
- Não guardar senhas em commits, issues, capturas ou instruções de publicação.
- Não habilitar GitHub Actions/FTP automático com senha gravada no código. Este pacote não contém automação de deploy.
- Manter HTTPS, validação de certificados SMTP e autenticação na portaria. Nunca tornar a validação de ingressos pública sem controle de acesso.
- Os links de ingresso contêm tokens de acesso; não publique links de compradores, códigos QR ou dados de pagamento.
- Backups incluem informações sensíveis e devem permanecer fora de `/www`. Backup do código no GitHub não inclui pedidos ou alunos.
- A rotina de 15 dias requer configuração e verificação do agendamento na hospedagem. Não assuma que está ativa apenas porque existe `backup-cron.php`.

Esta organização não substitui auditoria de segurança, teste de restauração ou revisão de permissões do servidor.

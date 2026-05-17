# `s3-satis` tool

Tool to generate a [Composer](https://getcomposer.org/) PHP packages repository
(based on [Satis - static Composer repository generator](https://github.com/composer/satis))
and synchronize generated repository with a [Amazon S3](https://aws.amazon.com/s3/)
(or compatible) bucket.


Check full documentation here: [opensource.duma.sh/systems/serverless-satis/s3-satis](https://opensource.duma.sh/systems/serverless-satis/s3-satis)


## Setup

You can install `s3-satis` tool in four ways:

- As a [Docker](https://www.docker.com/) container -using image [ghcr.io/kduma-oss/s3-satis](https://github.com/kduma-OSS/CLI-s3-satis/pkgs/container/s3-satis)
- Global composer installation - tool will be available globally as `s3-satis` command
- You can download phar executable file from [GitHub Releases](https://github.com/kduma-OSS/CLI-s3-satis/releases/latest) page
- Download source code form [GitHub](https://github.com/kduma-OSS/CLI-s3-satis) to run

## Usage

First prepare a `satis.json` file with your repository configuration.
This tool is based on [Satis - static Composer repository generator](https://github.com/composer/satis){:target="_blank"},
so please check [Satis documentation](https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md){:target="_blank"}
for configuration options.

```json
{
  "name": "my/repo",

  "homepage": "https://satis.example.com",

  "repositories": [
    { "type": "vcs", "url": "https://github.com/laravel/framework" }
  ],

  "require-all": true
}
```

Second, configure your environment variables. Three options:

**Opção A — Manual:**
```bash
cp .env.example .env
# Edite .env com suas credenciais
```

**Opção B — 1Password com `op run` (recomendado):**
```bash
op run --env-file=.env.example -- s3-satis build satis.json
```

**Opção C — 1Password com `op inject`:**
```bash
op inject -i .env.production.tpl -o .env
```

| Arquivo | Descrição |
|---------|-----------|
| `.env.example` | Template com refs `op://` para uso com `op run` |
| `.env.production.tpl` | Template com `{{ op:// }}` para uso com `op inject` |

Credenciais armazenadas no 1Password (vault `DEV-LEGACY`, item `cli-s3-satis`).

Then run `s3-satis` tool to generate repository and upload it to S3 bucket:
```bash
s3-satis build satis.json
```

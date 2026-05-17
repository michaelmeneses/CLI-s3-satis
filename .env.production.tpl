# Injetar via: op inject -i .env.production.tpl -o .env

# Satis config
SATIS_NAME=middag/satis
SATIS_URL=https://satis.middag.com.br
SATIS_BASEDIR=/private/var/www/middag.satis.localhost
SATIS_OUTPUTDIR=/private/var/www/middag.satis.localhost
REPO_BRANCH=master
PHP_BIN=/opt/homebrew/opt/php@8.2/bin/php

# Upload files to S3-compatible storage (Cloudflare R2)
S3_BUCKET={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/bucket }}
AWS_ACCESS_KEY_ID={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/access-key-id }}
AWS_SECRET_ACCESS_KEY={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/secret-access-key }}
AWS_DEFAULT_REGION=auto
AWS_REGION=auto
S3_ENDPOINT={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/endpoint-url }}
S3_USE_PATH_STYLE_ENDPOINT=true

# R2 aliases (same credentials)
R2_BUCKET_NAME={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/bucket }}
R2_ACCOUNT_ID={{ op://DEV-LEGACY/cli-s3-satis/cloudflare/account-id }}
R2_ACCESS_KEY_ID={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/access-key-id }}
R2_SECRET_ACCESS_KEY={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/secret-access-key }}
R2_ENDPOINT={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/endpoint-url }}
S3_ACCESS_KEY_ID={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/access-key-id }}
S3_SECRET_ACCESS_KEY={{ op://DEV-LEGACY/cli-s3-satis/aws-storage/secret-access-key }}
S3_REGION=auto

# Deploy to Cloudflare Pages
CLOUDFLARE_ACCOUNT_ID={{ op://DEV-LEGACY/cli-s3-satis/cloudflare/account-id }}
CLOUDFLARE_API_TOKEN={{ op://DEV-LEGACY/cli-s3-satis/cloudflare/api-token }}
CF_PAGES_PROJECT={{ op://DEV-LEGACY/cli-s3-satis/cloudflare/pages-project }}

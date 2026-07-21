const port = process.env.PORT ?? '3000';
const host = process.env.PUBLIC_HOST ?? 'localhost';
const token = process.env.INTERNAL_API_TOKEN;

if (!token) {
  console.error('INTERNAL_API_TOKEN is not set in the environment — check your .env file.');
  process.exit(1);
}

console.log(
  `\nOpen this URL to configure Plaid credentials:\n\n  http://${host}:${port}/setup?token=${token}\n`,
);

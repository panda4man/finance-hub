import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { ConfigService } from '@nestjs/config';
import { Logger } from '@nestjs/common';
import { AppModule } from './app.module';
import type { Env } from './config/env.validation';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  const config = app.get(ConfigService<Env, true>);
  const port = config.get('APP_PORT', { infer: true });

  await app.listen(port);
  Logger.log(`Finance Hub listening on port ${port}`, 'Bootstrap');
}

bootstrap();

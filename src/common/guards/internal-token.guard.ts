import { CanActivate, ExecutionContext, Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type { Request } from 'express';
import type { Env } from '../../config/env.validation';

@Injectable()
export class InternalTokenGuard implements CanActivate {
  constructor(private readonly config: ConfigService<Env, true>) {}

  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest<Request>();
    const expected = this.config.get<string>('INTERNAL_API_TOKEN');

    const header = request.headers.authorization;
    const bearerToken = header?.startsWith('Bearer ') ? header.slice('Bearer '.length) : undefined;
    const queryToken = typeof request.query.token === 'string' ? request.query.token : undefined;

    if (bearerToken === expected || queryToken === expected) {
      return true;
    }

    throw new UnauthorizedException('Invalid or missing internal API token');
  }
}

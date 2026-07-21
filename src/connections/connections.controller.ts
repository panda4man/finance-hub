import { Body, Controller, Get, Post, Res, UseGuards } from '@nestjs/common';
import type { Response } from 'express';
import { readFileSync } from 'fs';
import { join } from 'path';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { ConnectionsService } from './connections.service';

@Controller()
@UseGuards(InternalTokenGuard)
export class ConnectionsController {
  constructor(private readonly connections: ConnectionsService) {}

  @Get('connections')
  serveConnectionsPage(@Res() res: Response) {
    const html = readFileSync(join(__dirname, 'public', 'connections.html'), 'utf8');
    res.type('html').send(html);
  }

  @Get('internal/connections')
  async list() {
    return this.connections.listConnections();
  }

  @Post('api/connections')
  async create(@Body('setup_token') setupToken: string) {
    return this.connections.createOrRefreshFromSetupToken(setupToken);
  }
}

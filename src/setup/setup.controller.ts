import { Body, Controller, Get, Post, Res, UseGuards } from '@nestjs/common';
import type { Response } from 'express';
import { readFileSync } from 'fs';
import { join } from 'path';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { SettingsService } from '../settings/settings.service';

interface PlaidCredentialsBody {
  client_id: string;
  secret: string;
  env: string;
  redirect_uri?: string;
}

@Controller()
@UseGuards(InternalTokenGuard)
export class SetupController {
  constructor(private readonly settings: SettingsService) {}

  @Get('setup')
  serveSetupPage(@Res() res: Response) {
    const html = readFileSync(join(__dirname, 'public', 'setup.html'), 'utf8');
    res.type('html').send(html);
  }

  @Get('api/setup/plaid-credentials')
  async getPlaidCredentials() {
    return this.settings.getPlaidCredentialsMasked();
  }

  @Post('api/setup/plaid-credentials')
  async setPlaidCredentials(@Body() body: PlaidCredentialsBody) {
    await this.settings.setPlaidCredentials({
      clientId: body.client_id,
      secret: body.secret,
      env: body.env,
      redirectUri: body.redirect_uri,
    });
    return { status: 'ok' };
  }
}

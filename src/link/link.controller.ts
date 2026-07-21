import { Body, Controller, Get, Post, Res, UseGuards } from '@nestjs/common';
import type { Response } from 'express';
import { readFileSync } from 'fs';
import { join } from 'path';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { ItemsService } from '../items/items.service';
import { PlaidService } from '../plaid/plaid.service';

@Controller()
@UseGuards(InternalTokenGuard)
export class LinkController {
  constructor(
    private readonly plaid: PlaidService,
    private readonly items: ItemsService,
  ) {}

  @Get('link')
  serveLinkPage(@Res() res: Response) {
    const html = readFileSync(join(__dirname, 'public', 'link.html'), 'utf8');
    res.type('html').send(html);
  }

  /** With `item_id`, creates an update-mode link_token for re-auth of an existing item. */
  @Post('api/link/token')
  async createLinkToken(@Body('item_id') itemId?: string) {
    const userId = await this.items.getDefaultUserId();
    const accessToken = itemId ? await this.items.decryptAccessToken(itemId) : undefined;
    const linkToken = await this.plaid.createLinkToken(userId, accessToken);
    return { link_token: linkToken };
  }

  @Post('api/link/exchange')
  async exchange(@Body('public_token') publicToken: string) {
    return this.items.createFromPublicToken(publicToken);
  }

  /** Called after the user completes update-mode Link; the access_token doesn't change. */
  @Post('api/link/reauth-complete')
  async reauthComplete(@Body('item_id') itemId: string) {
    await this.items.markReauthComplete(itemId);
    return { status: 'ok' };
  }
}

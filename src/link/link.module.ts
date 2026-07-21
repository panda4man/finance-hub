import { Module } from '@nestjs/common';
import { PlaidModule } from '../plaid/plaid.module';
import { ItemsModule } from '../items/items.module';
import { LinkController } from './link.controller';

@Module({
  imports: [PlaidModule, ItemsModule],
  controllers: [LinkController],
})
export class LinkModule {}

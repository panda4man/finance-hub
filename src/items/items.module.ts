import { Module } from '@nestjs/common';
import { CryptoModule } from '../crypto/crypto.module';
import { PlaidModule } from '../plaid/plaid.module';
import { ItemsService } from './items.service';
import { ItemsController } from './items.controller';

@Module({
  imports: [CryptoModule, PlaidModule],
  providers: [ItemsService],
  controllers: [ItemsController],
  exports: [ItemsService],
})
export class ItemsModule {}

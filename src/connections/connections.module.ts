import { Module } from '@nestjs/common';
import { CryptoModule } from '../crypto/crypto.module';
import { SimplefinModule } from '../simplefin/simplefin.module';
import { ConnectionsService } from './connections.service';
import { ConnectionsController } from './connections.controller';

@Module({
  imports: [CryptoModule, SimplefinModule],
  providers: [ConnectionsService],
  controllers: [ConnectionsController],
  exports: [ConnectionsService],
})
export class ConnectionsModule {}

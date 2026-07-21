import { Module } from '@nestjs/common';
import { CryptoModule } from '../crypto/crypto.module';
import { SettingsService } from './settings.service';

@Module({
  imports: [CryptoModule],
  providers: [SettingsService],
  exports: [SettingsService],
})
export class SettingsModule {}

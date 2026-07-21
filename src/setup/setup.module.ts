import { Module } from '@nestjs/common';
import { SettingsModule } from '../settings/settings.module';
import { SetupController } from './setup.controller';

@Module({
  imports: [SettingsModule],
  controllers: [SetupController],
})
export class SetupModule {}

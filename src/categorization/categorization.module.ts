import { Module } from '@nestjs/common';
import { CategorizationService } from './categorization.service';
import { CategorizationController } from './categorization.controller';

@Module({
  providers: [CategorizationService],
  controllers: [CategorizationController],
  exports: [CategorizationService],
})
export class CategorizationModule {}

import { Module } from '@nestjs/common';
import { SimplefinService } from './simplefin.service';

@Module({
  providers: [SimplefinService],
  exports: [SimplefinService],
})
export class SimplefinModule {}

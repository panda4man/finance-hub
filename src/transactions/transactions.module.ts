import { Module } from '@nestjs/common';
import { TransactionsService } from './transactions.service';
import { TransactionsController } from './transactions.controller';
import { TransactionsPublicController } from './transactions-public.controller';

@Module({
  providers: [TransactionsService],
  controllers: [TransactionsController, TransactionsPublicController],
})
export class TransactionsModule {}

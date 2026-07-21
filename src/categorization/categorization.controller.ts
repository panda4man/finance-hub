import { Controller, Post, UseGuards } from '@nestjs/common';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { CategorizationService } from './categorization.service';

@Controller('internal/categorization')
@UseGuards(InternalTokenGuard)
export class CategorizationController {
  constructor(private readonly categorization: CategorizationService) {}

  @Post('recategorize')
  async recategorize() {
    return this.categorization.recategorizeAll();
  }
}

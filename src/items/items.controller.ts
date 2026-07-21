import { Controller, Get, UseGuards } from '@nestjs/common';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { ItemsService } from './items.service';

@Controller('internal/items')
@UseGuards(InternalTokenGuard)
export class ItemsController {
  constructor(private readonly items: ItemsService) {}

  @Get()
  async list() {
    return this.items.listItems();
  }
}

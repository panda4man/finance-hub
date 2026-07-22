import { PAGE_SIZE } from '../hooks/useFilters';

interface PaginationProps {
  offset: number;
  total: number;
  onOffsetChange: (offset: number) => void;
}

export function Pagination({ offset, total, onOffsetChange }: PaginationProps) {
  const start = total === 0 ? 0 : offset + 1;
  const end = Math.min(offset + PAGE_SIZE, total);
  const canPrev = offset > 0;
  const canNext = offset + PAGE_SIZE < total;

  return (
    <div className="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 text-sm text-gray-600">
      <span>
        {start}–{end} of {total}
      </span>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={!canPrev}
          onClick={() => onOffsetChange(Math.max(0, offset - PAGE_SIZE))}
          className="rounded-md border border-gray-300 px-3 py-1.5 font-medium disabled:opacity-40 enabled:hover:bg-gray-50"
        >
          Prev
        </button>
        <button
          type="button"
          disabled={!canNext}
          onClick={() => onOffsetChange(offset + PAGE_SIZE)}
          className="rounded-md border border-gray-300 px-3 py-1.5 font-medium disabled:opacity-40 enabled:hover:bg-gray-50"
        >
          Next
        </button>
      </div>
    </div>
  );
}

interface DateRangeFilterProps {
  dateFrom?: string;
  dateTo?: string;
  onChange: (dateFrom: string | undefined, dateTo: string | undefined) => void;
}

export function DateRangeFilter({ dateFrom, dateTo, onChange }: DateRangeFilterProps) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">Date range</label>
      <div className="flex items-center gap-2">
        <input
          type="date"
          value={dateFrom ?? ''}
          onChange={(e) => onChange(e.target.value || undefined, dateTo)}
          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
        />
        <span className="text-gray-400">–</span>
        <input
          type="date"
          value={dateTo ?? ''}
          onChange={(e) => onChange(dateFrom, e.target.value || undefined)}
          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
        />
      </div>
    </div>
  );
}

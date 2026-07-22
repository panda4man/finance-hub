import type { Category } from '../../api/types';

interface CategorySelectProps {
  categories: Category[];
  selected?: string;
  onChange: (slug: string | undefined) => void;
}

export function CategorySelect({ categories, selected, onChange }: CategorySelectProps) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">Category</label>
      <select
        value={selected ?? ''}
        onChange={(e) => onChange(e.target.value || undefined)}
        className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
      >
        <option value="">All categories</option>
        {categories.map((category) => (
          <option key={category.id} value={category.slug}>
            {category.name}
          </option>
        ))}
      </select>
    </div>
  );
}

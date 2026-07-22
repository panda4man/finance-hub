import { useEffect, useState } from 'react';

interface SearchBoxProps {
  value?: string;
  onChange: (value: string | undefined) => void;
}

const DEBOUNCE_MS = 300;

export function SearchBox({ value, onChange }: SearchBoxProps) {
  const [draft, setDraft] = useState(value ?? '');

  useEffect(() => {
    setDraft(value ?? '');
  }, [value]);

  useEffect(() => {
    const timer = setTimeout(() => {
      const trimmed = draft.trim();
      if (trimmed !== (value ?? '')) {
        onChange(trimmed || undefined);
      }
    }, DEBOUNCE_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft]);

  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">Search</label>
      <input
        type="search"
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        placeholder="Merchant or description"
        className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
      />
    </div>
  );
}

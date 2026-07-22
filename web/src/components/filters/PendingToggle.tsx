interface PendingToggleProps {
  value?: boolean;
  onChange: (value: boolean | undefined) => void;
}

const OPTIONS: { label: string; value: boolean | undefined }[] = [
  { label: 'All', value: undefined },
  { label: 'Posted', value: false },
  { label: 'Pending', value: true },
];

export function PendingToggle({ value, onChange }: PendingToggleProps) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">Status</label>
      <div className="inline-flex rounded-md border border-gray-300 text-sm">
        {OPTIONS.map((option, i) => (
          <button
            key={option.label}
            type="button"
            onClick={() => onChange(option.value)}
            className={[
              'px-3 py-1.5 font-medium',
              i > 0 ? 'border-l border-gray-300' : '',
              value === option.value ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 hover:bg-gray-50',
              i === 0 ? 'rounded-l-md' : '',
              i === OPTIONS.length - 1 ? 'rounded-r-md' : '',
            ].join(' ')}
          >
            {option.label}
          </button>
        ))}
      </div>
    </div>
  );
}

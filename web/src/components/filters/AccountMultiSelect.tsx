import type { Account } from '../../api/types';

interface AccountMultiSelectProps {
  accounts: Account[];
  selected: string[];
  onChange: (accountIds: string[]) => void;
}

export function AccountMultiSelect({ accounts, selected, onChange }: AccountMultiSelectProps) {
  function toggle(id: string) {
    onChange(selected.includes(id) ? selected.filter((a) => a !== id) : [...selected, id]);
  }

  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-700">Accounts</label>
      <div className="max-h-40 space-y-1 overflow-y-auto rounded-md border border-gray-300 p-2">
        {accounts.length === 0 && <p className="text-sm text-gray-400">No accounts</p>}
        {accounts.map((account) => (
          <label key={account.id} className="flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={selected.includes(account.id)}
              onChange={() => toggle(account.id)}
              className="rounded border-gray-300"
            />
            <span className="truncate">
              {account.name}
              {account.mask ? ` ••${account.mask}` : ''}
            </span>
          </label>
        ))}
      </div>
    </div>
  );
}

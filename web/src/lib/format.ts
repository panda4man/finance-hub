export function formatAmount(amount: string, isoCurrencyCode: string | null): string {
  const value = Number(amount);
  if (Number.isNaN(value)) return amount;
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: isoCurrencyCode ?? 'USD',
    }).format(value);
  } catch {
    return value.toFixed(2);
  }
}

export function formatDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number);
  if (!year || !month || !day) return date;
  return new Date(year, month - 1, day).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

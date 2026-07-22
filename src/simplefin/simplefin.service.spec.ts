import { SimplefinService } from './simplefin.service';

function jsonResponse(body: unknown): Response {
  return {
    ok: true,
    json: async () => body,
  } as Response;
}

describe('SimplefinService', () => {
  const credential = 'https://user:pass@bridge.example.com/simplefin';

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('falls back to transacted_at for the date when posted is 0 (still-pending transaction)', async () => {
    jest.spyOn(global, 'fetch').mockResolvedValue(
      jsonResponse({
        errlist: [],
        accounts: [
          {
            id: 'acc-1',
            name: 'Checking',
            conn_id: 'conn-1',
            currency: 'USD',
            balance: '100.00',
            'balance-date': 1784635200,
            transactions: [
              {
                id: 'txn-1',
                posted: 0,
                transacted_at: 1784635200,
                amount: '-15.00',
                description: 'PAYMENT TO CHASE CARD ENDING IN 4876 07/21',
                pending: true,
              },
            ],
          },
        ],
      }),
    );

    const service = new SimplefinService();
    const page = await service.fetchAccountSet(credential);

    const [txn] = page.accounts[0].transactions;
    expect(txn.date).toBe('2026-07-21');
    expect(txn.datetime).toEqual(new Date(1784635200 * 1000));
  });

  it('uses posted as-is when it is a real, non-zero timestamp', async () => {
    jest.spyOn(global, 'fetch').mockResolvedValue(
      jsonResponse({
        errlist: [],
        accounts: [
          {
            id: 'acc-1',
            name: 'Checking',
            conn_id: 'conn-1',
            currency: 'USD',
            balance: '100.00',
            'balance-date': 1784635200,
            transactions: [
              {
                id: 'txn-2',
                posted: 1745337600,
                amount: '10.00',
                description: 'DUKEENERGY BILL PAY',
                pending: false,
              },
            ],
          },
        ],
      }),
    );

    const service = new SimplefinService();
    const page = await service.fetchAccountSet(credential);

    const [txn] = page.accounts[0].transactions;
    expect(txn.date).toBe('2025-04-22');
  });
});

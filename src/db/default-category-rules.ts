export interface CategoryRuleSeedEntry {
  pattern: string;
  // must match a categories.slug produced by seed-categories.ts
  // (slug = entry.detailed.toLowerCase() in DEFAULT_CATEGORY_TAXONOMY)
  categorySlug: string;
  amountSign?: 'any' | 'outflow' | 'inflow';
  priority?: number;
}

/**
 * Representative starter rule set matched case-insensitively as a substring
 * against transactions.name. Not exhaustive — covers common merchants across
 * a sample of DEFAULT_CATEGORY_TAXONOMY categories.
 */
export const DEFAULT_CATEGORY_RULES: CategoryRuleSeedEntry[] = [
  // coffee
  { pattern: 'starbucks', categorySlug: 'food_and_drink_coffee' },
  { pattern: 'dunkin', categorySlug: 'food_and_drink_coffee' },
  { pattern: 'peet', categorySlug: 'food_and_drink_coffee' },

  // groceries
  { pattern: 'trader joe', categorySlug: 'food_and_drink_groceries' },
  { pattern: 'whole foods', categorySlug: 'food_and_drink_groceries' },
  { pattern: 'safeway', categorySlug: 'food_and_drink_groceries' },
  { pattern: 'kroger', categorySlug: 'food_and_drink_groceries' },

  // gas stations
  { pattern: 'shell oil', categorySlug: 'transportation_gas' },
  { pattern: 'chevron', categorySlug: 'transportation_gas' },
  { pattern: 'exxon', categorySlug: 'transportation_gas' },

  // streaming / subscriptions
  { pattern: 'netflix', categorySlug: 'entertainment_tv_and_movies' },
  { pattern: 'hulu', categorySlug: 'entertainment_tv_and_movies' },
  { pattern: 'spotify', categorySlug: 'entertainment_music_and_audio' },

  // rideshare
  { pattern: 'uber', categorySlug: 'transportation_taxis_and_ride_shares' },
  { pattern: 'lyft', categorySlug: 'transportation_taxis_and_ride_shares' },

  // pharmacies
  { pattern: 'cvs/pharmacy', categorySlug: 'medical_pharmacies_and_supplements' },
  { pattern: 'walgreens', categorySlug: 'medical_pharmacies_and_supplements' },

  // utilities / telecom
  { pattern: 'comcast', categorySlug: 'rent_and_utilities_internet_and_cable' },
  { pattern: 'verizon wireless', categorySlug: 'rent_and_utilities_telephone' },
  { pattern: 'at&t', categorySlug: 'rent_and_utilities_telephone' },

  // ATM / bank fees
  { pattern: 'atm withdrawal', categorySlug: 'bank_fees_atm_fees' },
  { pattern: 'overdraft fee', categorySlug: 'bank_fees_overdraft_fees' },
  { pattern: 'nsf fee', categorySlug: 'bank_fees_insufficient_funds' },

  // payroll / income
  { pattern: 'payroll', categorySlug: 'income_wages', amountSign: 'inflow' },
  { pattern: 'direct deposit', categorySlug: 'income_wages', amountSign: 'inflow' },

  // insurance
  { pattern: 'geico', categorySlug: 'general_services_insurance' },

  // general merchandise
  { pattern: 'amazon', categorySlug: 'general_merchandise_online_marketplaces' },
  { pattern: 'walmart', categorySlug: 'general_merchandise_superstores' },
  { pattern: 'costco', categorySlug: 'general_merchandise_superstores' },

  // credit card payments (both legs: outflow from checking, inflow on the card)
  { pattern: 'payment to chase card', categorySlug: 'loan_payments_credit_card_payment' },
  { pattern: 'thank you', categorySlug: 'loan_payments_credit_card_payment' },

  // recurring services
  { pattern: 'no-ip', categorySlug: 'general_services_other_general_services' },
  { pattern: 'simplefin', categorySlug: 'bank_fees_other_bank_fees' },

  // telecom / internet
  { pattern: 'altafiber', categorySlug: 'rent_and_utilities_internet_and_cable' },
];

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
  { pattern: 'bp#', categorySlug: 'transportation_gas' },

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
  { pattern: 'wal-mart', categorySlug: 'general_merchandise_superstores' },
  { pattern: 'costco', categorySlug: 'general_merchandise_superstores' },
  { pattern: 'ebay', categorySlug: 'general_merchandise_online_marketplaces' },
  { pattern: 'micro center', categorySlug: 'general_merchandise_electronics' },
  { pattern: 'sierra #', categorySlug: 'general_merchandise_clothing_and_accessories' },
  { pattern: 'once upon a chld', categorySlug: 'general_merchandise_clothing_and_accessories' },
  { pattern: 'kindred bravely', categorySlug: 'general_merchandise_clothing_and_accessories' },
  { pattern: 'phantom fireworks', categorySlug: 'general_merchandise_other_general_merchandise' },
  { pattern: 'wyze labs', categorySlug: 'general_merchandise_electronics' },

  // credit card payments (both legs: outflow from checking, inflow on the card)
  { pattern: 'payment to chase card', categorySlug: 'loan_payments_credit_card_payment' },
  { pattern: 'thank you', categorySlug: 'loan_payments_credit_card_payment' },
  { pattern: 'ollo cc', categorySlug: 'loan_payments_credit_card_payment' },

  // mortgage
  { pattern: 'rocket mortgage', categorySlug: 'loan_payments_mortgage_payment' },

  // recurring services
  { pattern: 'no-ip', categorySlug: 'general_services_other_general_services' },
  { pattern: 'simplefin', categorySlug: 'bank_fees_other_bank_fees' },
  { pattern: 'apple.com/bill', categorySlug: 'general_services_other_general_services' },
  { pattern: 'fiverr', categorySlug: 'general_services_other_general_services' },
  { pattern: 'zety.com', categorySlug: 'general_services_other_general_services' },
  { pattern: 'claude.ai subscription', categorySlug: 'general_services_other_general_services' },
  { pattern: 'openai', categorySlug: 'general_services_other_general_services' },
  { pattern: 'linkedin', categorySlug: 'general_services_other_general_services' },
  { pattern: 'name-cheap.com', categorySlug: 'general_services_other_general_services' },
  { pattern: 'porkbun', categorySlug: 'general_services_other_general_services' },
  { pattern: 'vultr', categorySlug: 'general_services_other_general_services' },
  { pattern: 'paddle.net', categorySlug: 'general_services_other_general_services' },
  { pattern: 'usenetserver', categorySlug: 'general_services_other_general_services' },
  { pattern: 'usps kiosk', categorySlug: 'general_services_postage_and_shipping' },
  { pattern: 'autozone', categorySlug: 'general_services_automotive' },
  { pattern: "mike's carwash", categorySlug: 'general_services_automotive' },
  { pattern: 'aptive environmental', categorySlug: 'home_improvement_repair_and_maintenance' },
  { pattern: 'schneller knochelmann', categorySlug: 'home_improvement_repair_and_maintenance' },

  // home improvement
  { pattern: 'home depot', categorySlug: 'home_improvement_hardware' },
  { pattern: 'lowes #', categorySlug: 'home_improvement_hardware' },

  // groceries
  { pattern: 'aldi', categorySlug: 'food_and_drink_groceries' },
  { pattern: 'remke', categorySlug: 'food_and_drink_groceries' },

  // fast food
  { pattern: "chick-fil-a", categorySlug: 'food_and_drink_fast_food' },
  { pattern: 'jimmy johns', categorySlug: 'food_and_drink_fast_food' },
  { pattern: "mcdonald's", categorySlug: 'food_and_drink_fast_food' },
  { pattern: "raising canes", categorySlug: 'food_and_drink_fast_food' },
  { pattern: 'tropical smoothie', categorySlug: 'food_and_drink_fast_food' },

  // restaurants (sit-down / casual dining, including named local spots)
  { pattern: "chili's", categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'carrabbas', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'longhorn steak', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'bob evans', categorySlug: 'food_and_drink_restaurant' },
  { pattern: '3 ladies thai', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'basil thai kitchen', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'asian place', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'cancun mexican', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'osaka ramen', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'symphony mediterrane', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'miyako sushi', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'wild eggs', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'pholicious', categorySlug: 'food_and_drink_restaurant' },
  { pattern: 'guruindiarestaura', categorySlug: 'food_and_drink_restaurant' },
  { pattern: "tchoa ii", categorySlug: 'food_and_drink_restaurant' },
  // POS-processor prefixes used almost exclusively by restaurants; low
  // priority so a more specific named rule above always wins first.
  { pattern: 'tst*', categorySlug: 'food_and_drink_restaurant', priority: 200 },
  { pattern: 'dd *', categorySlug: 'food_and_drink_restaurant', priority: 200 },

  // personal care
  { pattern: 'bomdia massage', categorySlug: 'personal_care_other_personal_care' },
  { pattern: 'dr. squatch', categorySlug: 'personal_care_hair_and_beauty' },

  // medical
  { pattern: 'mccounseling', categorySlug: 'medical_other_medical' },
  { pattern: 'ent & allergy specialist', categorySlug: 'medical_other_medical' },
  { pattern: 'christ hospital', categorySlug: 'medical_other_medical' },
  { pattern: 'rupa labs', categorySlug: 'medical_other_medical' },
  { pattern: 'fullscript', categorySlug: 'medical_pharmacies_and_supplements' },
  { pattern: 'cellcore biosciences', categorySlug: 'medical_pharmacies_and_supplements' },

  // pets
  { pattern: 'petco', categorySlug: 'general_merchandise_pet_supplies' },
  { pattern: 'petland', categorySlug: 'general_merchandise_pet_supplies' },

  // telecom / internet
  { pattern: 'altafiber', categorySlug: 'rent_and_utilities_internet_and_cable' },
  { pattern: 'us mobile', categorySlug: 'rent_and_utilities_telephone' },
  { pattern: 'mint mobile', categorySlug: 'rent_and_utilities_telephone' },
  { pattern: 'ooma,inc', categorySlug: 'rent_and_utilities_telephone' },

  // utilities
  { pattern: 'dukeenergy', categorySlug: 'rent_and_utilities_gas_and_electricity' },
  { pattern: 'sanitation distr', categorySlug: 'rent_and_utilities_sewage_and_waste_management' },
  { pattern: 'northern kentuck billpay', categorySlug: 'rent_and_utilities_sewage_and_waste_management' },

  // insurance
  { pattern: 'usaa p&c autopay', categorySlug: 'general_services_insurance' },
  { pattern: 'usaa.com pay ext life', categorySlug: 'general_services_insurance' },

  // entertainment
  { pattern: 'crunchyroll', categorySlug: 'entertainment_tv_and_movies' },
  { pattern: 'blurry crea', categorySlug: 'entertainment_other_entertainment' },
  { pattern: 'blurrycreatures', categorySlug: 'entertainment_other_entertainment' },

  // person-to-person transfers (direction disambiguated by the descriptor
  // text itself, not amount sign, since a single pattern can only map to one
  // category — see uq_category_rules_pattern_field)
  { pattern: 'zelle payment to', categorySlug: 'transfer_out_account_transfer' },
  { pattern: 'zelle payment from', categorySlug: 'transfer_in_account_transfer' },
  { pattern: 'venmo cashout', categorySlug: 'transfer_in_account_transfer' },
  { pattern: 'venmo payment', categorySlug: 'transfer_out_account_transfer' },
  { pattern: 'venmo purchase', categorySlug: 'transfer_out_account_transfer' },
  { pattern: 'paypal inst xfer', categorySlug: 'transfer_out_account_transfer' },
  { pattern: 'paypal transfer', categorySlug: 'transfer_in_account_transfer' },
  { pattern: 'ally bank p2p', categorySlug: 'transfer_out_account_transfer' },

  // internal savings transfers
  { pattern: 'online transfer from sav', categorySlug: 'transfer_in_savings' },
  { pattern: 'online transfer to sav', categorySlug: 'transfer_out_savings' },
  { pattern: 'transfer to sav', categorySlug: 'transfer_out_savings' },

  // deposits
  { pattern: 'atm cash deposit', categorySlug: 'transfer_in_deposit' },
  { pattern: 'remote online deposit', categorySlug: 'transfer_in_deposit' },
];

import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import {
  Configuration,
  PlaidApi,
  PlaidEnvironments,
  Products,
  CountryCode,
  LinkTokenCreateRequest,
} from 'plaid';
import type { Env } from '../config/env.validation';

@Injectable()
export class PlaidService {
  readonly client: PlaidApi;
  private readonly countryCodes: CountryCode[];
  private readonly language: string;
  private readonly redirectUri?: string;
  private readonly webhookUrl?: string;

  constructor(config: ConfigService<Env, true>) {
    const plaidEnv = config.get<string>('PLAID_ENV');
    const configuration = new Configuration({
      basePath: PlaidEnvironments[plaidEnv],
      baseOptions: {
        headers: {
          'PLAID-CLIENT-ID': config.get<string>('PLAID_CLIENT_ID'),
          'PLAID-SECRET': config.get<string>('PLAID_SECRET'),
        },
      },
    });
    this.client = new PlaidApi(configuration);

    this.countryCodes = config
      .get<string>('PLAID_COUNTRY_CODES')
      .split(',')
      .map((code) => code.trim() as CountryCode);
    this.language = config.get<string>('PLAID_LANGUAGE');
    this.redirectUri = config.get<string | undefined>('PLAID_REDIRECT_URI') || undefined;
    this.webhookUrl = config.get<string | undefined>('PLAID_WEBHOOK_URL') || undefined;
  }

  /**
   * Creates a link_token for the initial Link flow, or for update mode
   * (re-auth) when `accessToken` is supplied.
   */
  async createLinkToken(userId: string, accessToken?: string): Promise<string> {
    const request: LinkTokenCreateRequest = {
      user: { client_user_id: userId },
      client_name: 'Finance Hub',
      language: this.language,
      country_codes: this.countryCodes,
      redirect_uri: this.redirectUri,
      webhook: this.webhookUrl,
      ...(accessToken
        ? { access_token: accessToken }
        : { products: [Products.Transactions] }),
    };

    const response = await this.client.linkTokenCreate(request);
    return response.data.link_token;
  }

  async exchangePublicToken(publicToken: string) {
    const response = await this.client.itemPublicTokenExchange({ public_token: publicToken });
    return { accessToken: response.data.access_token, itemId: response.data.item_id };
  }

  async getItem(accessToken: string) {
    const response = await this.client.itemGet({ access_token: accessToken });
    return response.data.item;
  }

  async getInstitution(institutionId: string) {
    const response = await this.client.institutionsGetById({
      institution_id: institutionId,
      country_codes: this.countryCodes,
    });
    return response.data.institution;
  }

  async getAccounts(accessToken: string) {
    const response = await this.client.accountsGet({ access_token: accessToken });
    return response.data.accounts;
  }

  async transactionsSync(accessToken: string, cursor?: string) {
    const response = await this.client.transactionsSync({
      access_token: accessToken,
      cursor,
    });
    return response.data;
  }
}

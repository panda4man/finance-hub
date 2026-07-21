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
import { SettingsService } from '../settings/settings.service';

@Injectable()
export class PlaidService {
  private readonly countryCodes: CountryCode[];
  private readonly language: string;
  private readonly webhookUrl?: string;

  constructor(
    private readonly config: ConfigService<Env, true>,
    private readonly settings: SettingsService,
  ) {
    this.countryCodes = config
      .get<string>('PLAID_COUNTRY_CODES')
      .split(',')
      .map((code) => code.trim() as CountryCode);
    this.language = config.get<string>('PLAID_LANGUAGE');
    this.webhookUrl = config.get<string | undefined>('PLAID_WEBHOOK_URL') || undefined;
  }

  /**
   * Built fresh per call (not cached) so credentials entered via the /setup
   * page take effect immediately, no restart required.
   */
  private async getClient(): Promise<PlaidApi> {
    const { clientId, secret, env } = await this.settings.getPlaidCredentials();
    const configuration = new Configuration({
      basePath: PlaidEnvironments[env],
      baseOptions: {
        headers: {
          'PLAID-CLIENT-ID': clientId,
          'PLAID-SECRET': secret,
        },
      },
    });
    return new PlaidApi(configuration);
  }

  /**
   * Creates a link_token for the initial Link flow, or for update mode
   * (re-auth) when `accessToken` is supplied.
   */
  async createLinkToken(userId: string, accessToken?: string): Promise<string> {
    const [client, { redirectUri }] = await Promise.all([
      this.getClient(),
      this.settings.getPlaidCredentials(),
    ]);

    const request: LinkTokenCreateRequest = {
      user: { client_user_id: userId },
      client_name: 'Finance Hub',
      language: this.language,
      country_codes: this.countryCodes,
      redirect_uri: redirectUri,
      webhook: this.webhookUrl,
      ...(accessToken ? { access_token: accessToken } : { products: [Products.Transactions] }),
    };

    const response = await client.linkTokenCreate(request);
    return response.data.link_token;
  }

  async exchangePublicToken(publicToken: string) {
    const client = await this.getClient();
    const response = await client.itemPublicTokenExchange({ public_token: publicToken });
    return { accessToken: response.data.access_token, itemId: response.data.item_id };
  }

  async getItem(accessToken: string) {
    const client = await this.getClient();
    const response = await client.itemGet({ access_token: accessToken });
    return response.data.item;
  }

  async getInstitution(institutionId: string) {
    const client = await this.getClient();
    const response = await client.institutionsGetById({
      institution_id: institutionId,
      country_codes: this.countryCodes,
    });
    return response.data.institution;
  }

  async getAccounts(accessToken: string) {
    const client = await this.getClient();
    const response = await client.accountsGet({ access_token: accessToken });
    return response.data.accounts;
  }

  async transactionsSync(accessToken: string, cursor?: string) {
    const client = await this.getClient();
    const response = await client.transactionsSync({
      access_token: accessToken,
      cursor,
    });
    return response.data;
  }
}

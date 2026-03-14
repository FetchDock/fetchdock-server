/**
 * data types
 */
import {AuthProvider, AuthRedirectResult, QueryFunctionContext, UserIdentity} from "react-admin";


type RefreshableAuthProvider = {
  canRefresh?: (params: any & QueryFunctionContext) => Promise<boolean>;
  refreshToken?: () => Promise<void>;
}

export type OAuthProvider = AuthProvider & RefreshableAuthProvider;

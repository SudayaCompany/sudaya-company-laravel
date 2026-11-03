export type AuthProps = {
  user: {
    id: string;
    fullName: string;
    email: string | null;
    company: string;
    roles: string[];
  };
  navigation: string[];
} | null;

export type SharedProps = { auth: AuthProps };

export type Flash = { success?: string; error?: string; link?: string };

export type Option = { id: string; label: string };

export type AccessibleOutlet = {
  id: string;
  code: string;
  name: string;
  brandId: string;
  brandName: string;
  timezone: string;
};

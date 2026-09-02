import type { Config } from "tailwindcss";

const config: Config = {
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        bg: "#f3f2f2",
        surface: "#eae9e9",
        ink: "#201e1d",
        divider: "rgba(32,30,29,0.4)",
        // Health-context accent pair (green primary / blue secondary), same
        // OKLCH-ramp structure as the Modernist design system's single red accent.
        accent: {
          DEFAULT: "#2f9d6c",
          100: "#eaf7f0",
          200: "#cdeedd",
          300: "#a3e0c2",
          400: "#6dcb9c",
          500: "#2f9d6c",
          600: "#1f7f57",
          700: "#146648",
          800: "#0f4d37",
          900: "#0a3527",
        },
        accent2: {
          DEFAULT: "#2f7cc4",
          100: "#eaf2fb",
          200: "#cfe4f6",
          300: "#a3cced",
          400: "#6aa9de",
          500: "#2f7cc4",
          600: "#1f5f9d",
          700: "#164a7c",
          800: "#0f3660",
          900: "#0a2547",
        },
        ink2: {
          100: "#f8f4f4",
          200: "#eae7e7",
          300: "#d7d3d3",
          400: "#bab6b6",
          500: "#9b9797",
          600: "#7d7979",
          700: "#605d5d",
          800: "#444141",
          900: "#2d2b2b",
        },
      },
      fontFamily: {
        heading: ["var(--font-archivo)", "system-ui", "sans-serif"],
        body: ["var(--font-archivo)", "system-ui", "sans-serif"],
      },
      borderRadius: {
        none: "0px",
        DEFAULT: "0px",
        md: "0px",
        lg: "0px",
      },
    },
  },
  plugins: [],
};

export default config;

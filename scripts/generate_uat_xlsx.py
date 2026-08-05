import pandas as pd
import os

CSV_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'UAT_Test_Book_SME_Stocqify.csv'))
OUT_XLSX = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'UAT_Test_Book_SME_Stocqify.xlsx'))

def format_steps(cell):
    s = str(cell)
    # try semicolon split first
    parts = [p.strip() for p in s.split(';') if p.strip()]
    if len(parts) <= 1:
        # fallback: split on newline or pipes
        parts = [p.strip() for p in s.replace('\r','').split('\n') if p.strip()]
    if len(parts) == 0:
        return ''
    return '\n'.join(f"{i+1}. {parts[i]}" for i in range(len(parts)))

def main():
    if not os.path.exists(CSV_PATH):
        print('CSV not found at', CSV_PATH)
        return
    df = pd.read_csv(CSV_PATH)
    if 'Role' not in df.columns:
        print('CSV missing Role column')
        return

    # prepare sheets by Role
    sheets = {}
    for role, grp in df.groupby('Role'):
        grp = grp.copy()
        grp['Steps'] = grp['Steps'].apply(format_steps)
        if 'Test Result' not in grp.columns:
            grp['Test Result'] = ''
        if 'Comments' not in grp.columns:
            grp['Comments'] = ''
        out_cols = ['Test ID', 'Feature', 'Description', 'Preconditions', 'Steps', 'Expected Result', 'Test Result', 'Comments']
        # ensure columns exist
        for c in out_cols:
            if c not in grp.columns:
                grp[c] = ''
        sheets[role[:31]] = grp[out_cols]

    with pd.ExcelWriter(OUT_XLSX, engine='openpyxl') as writer:
        for sheet_name, df_sheet in sheets.items():
            df_sheet.to_excel(writer, sheet_name=sheet_name, index=False)

    print('Wrote', OUT_XLSX)

if __name__ == '__main__':
    main()
